<?php

namespace KaraDAV;

use stdClass;

class Users
{
	static public function generatePassword(): string
	{
		$password = base64_encode(random_bytes(16));
		$password = substr(str_replace(['/', '+', '='], '', $password), 0, 16);
		return $password;
	}

	public function list(): array
	{
		return iterator_to_array(DB::getInstance()->iterate('SELECT * FROM users ORDER BY login;'));
	}

	public function get(string $login): ?stdClass
	{
		$user = DB::getInstance()->first('SELECT * FROM users WHERE login = ?;', $login);
		return $this->makeUserObjectGreatAgain($user);
	}

	protected function makeUserObjectGreatAgain(?stdClass $user): ?stdClass
	{
		if ($user) {
			$user->path = sprintf(STORAGE_PATH, $user->login);
			$user->path = rtrim($user->path, '/') . '/';

			if (!file_exists($user->path)) {
				mkdir($user->path, 0770, true);
			}

			$user->dav_url = WWW_URL . 'files/' . $user->login . '/';
		}

		return $user;
	}

	public function create(string $login, string $password)
	{
		$login = strtolower(trim($login));
		$hash = password_hash(trim($password), null);
		DB::getInstance()->run('INSERT OR IGNORE INTO users (login, password) VALUES (?, ?);', $login, $hash);
	}

	public function edit(string $login, array $data)
	{
		$params = [];

		if (isset($data['password'])) {
			$params['password'] = password_hash(trim($data['password']), null);
		}

		if (isset($data['quota'])) {
			$params['quota'] = (int) $data['quota'] * 1024 * 1024;
		}

		if (isset($data['is_admin'])) {
			$params['is_admin'] = (int) $data['is_admin'];
		}

		$update = array_map(fn($k) => $k . ' = ?', array_keys($params));
		$update = implode(', ', $update);
		$params = array_values($params);
		$params[] = $login;

		DB::getInstance()->run(sprintf('UPDATE users SET %s WHERE login = ?;', $update), ...$params);
	}

	public function current(): ?stdClass
	{
		if (isset($_COOKIE[session_name()]) && !isset($_SESSION)) {
			session_start();
		}

		return $this->makeUserObjectGreatAgain($_SESSION['user'] ?? null);
	}

	public function login(?string $login, ?string $password, ?string $app_password = null): ?stdClass
	{
		$login = null !== $login ? strtolower(trim($login)) : null;

		// Check if user already has a session
		$current = $this->current();

		if ($current && (!$login || $current->login == $login)) {
			return $current;
		}

		if (!$login || (!$password && !$app_password)) {
			return null;
		}

		// If not, try to login
		$user = $this->get($login);

		if (!$user) {
			return null;
		}

		if ($app_password) {
			$list = DB::getInstance()->iterate('SELECT password FROM app_sessions WHERE login = ? AND expiry > datetime();', $login);
			$ok = false;
			$app_password = trim($app_password) . $user->password;

			// We have to iterate on all sessions, as NextCloud does not provide a unique login
			foreach ($list as $session) {
				if (password_verify($app_password, $hash)) {
					$ok = true;
					break;
				}
			}

			if (!$ok) {
				return null;
			}
		}
		elseif (!password_verify(trim($password), $user->password)) {
			return null;
		}

		@session_start();
		$_SESSION['user'] = $user;

		return $user;
	}

	public function appSessionCreate(?string $token = null): ?string
	{
		$current = $this->current();

		if (!$current) {
			return null;
		}

		if (null === $token) {
			$expiry = '+10 minutes';
			$hash = null;
			$password = null;
		}
		else {
			$expiry = '+1 month';
			$password = $this->generatePassword();

			// The app password contains the user password hash
			// this way we can invalidate all sessions if we change
			// the user password
			$hash = password_hash($password . $current->password, null);
		}

		DB::getInstance()->run(
			'INSERT OR IGNORE INTO app_sessions (user, password, expiry, token) VALUES (?, ?, datetime(\'now\', ?), ?);',
			$current->login, $hash, $expiry, $token);

		return $password;
	}

	public function appSessionValidateToken(string $token): ?stdClass
	{
		$session = DB::getInstance()->first('SELECT * FROM app_sessions WHERE token = ?;', $token);

		if (!$session) {
			return null;
		}

		// the token can only be exchanged against a session once,
		// so we set a password and remove the token
		$session->password = $this->generatePassword();

		// The app password contains the user password hash
		// this way we can invalidate all sessions if we change
		// the user password
		$hash = password_hash($session->password . $current->password, null);

		DB::getInstance()->run('UPDATE app_sessions
			SET token = NULL, password = ?, expiry = datetime(\'now\', \'+1 month\')
			WHERE token = ?;',
			$hash, $token);

		return $session;
	}

	public function appSessionLogin(?string $login, ?string $app_password): ?stdClass
	{
		// From time to time, clean up old sessions
		if (random_int() % 100 == 0) {
			DB::getInstance()->run('DELETE FROM app_sessions WHERE expiry < datetime();');
		}

		return $this->login($login, null, $app_password);
	}

	public function quota(?stdClass $user = null): stdClass
	{
		$user ??= $this->current();
		$used = get_directory_size($user->path);
		$total = $user->quota;
		$free = $user->quota - $used;

		return (object) compact('free', 'total', 'used');
	}
}