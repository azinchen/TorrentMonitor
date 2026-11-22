<?php
$dir = dirname(__FILE__).'/';
require_once $dir.'bootstrap.php';

use TorrentMonitor\Data\Topics;
use TorrentMonitor\Tracker\Registry;

function csrf_verify()
{
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        ! hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        echo json_encode(['error' => true, 'msg' => 'CSRF token mismatch']);
        exit;
    }
}

if (isset($_POST['action']))
{
    if (session_status() !== PHP_SESSION_ACTIVE)
        session_start();

    csrf_verify();

	//Проверяем пароль
	if ($_POST['action'] == 'enter')
	{
		$stored = Database::getSetting('password');
		$input  = $_POST['password'];
		$ok = false;

		if (password_verify($input, $stored))
		{
		    $ok = true;
		}
		elseif (hash_equals($stored, md5($input)))
		{
		    $ok = true;
		    $stored = password_hash($input, PASSWORD_BCRYPT);
		    Database::updateCredentials($stored);
		}

		if ($ok)
		{
			$_SESSION['TM'] = $stored;
			$return['error'] = FALSE;
			if ($_POST['remember'] == 'true')
			    setcookie('TM', $stored, time()+3600*24*31, '/');
		}
		else
		{
			$return['error'] = TRUE;
			$return['msg'] = 'Неверный пароль!';
		}
		echo json_encode($return);
	}

    if ( ! Sys::checkAuth())
        exit();

	//Добавляем тему для мониторинга
	if ($_POST['action'] == 'torrent_add')
	{
		$return = array('error' => TRUE, 'msg' => 'Неверная ссылка.');
		$host = preg_replace('/^www\./', '', strtolower((string)parse_url((string)$_POST['url'], PHP_URL_HOST)));
		$parsed = Registry::fromUrl((string)$_POST['url']);
		if (Registry::kind($host) == 'series' && $host != 'baibako.tv')
			$return['msg'] = 'Это не форумный трекер. Добавьте как Сериал по его названию.';
		elseif ($parsed !== NULL)
		{
			$tracker = $parsed['tracker'];
			if ( ! Registry::ready($tracker))
				$return['msg'] = 'Вы не можете следить за этой темой на трекере - <b>'.$tracker.'</b>, пока не введёте свои учётные данные!';
			elseif (Topics::find($tracker, $parsed['topicId']) !== NULL)
				$return['msg'] = 'Вы уже следите за данной темой на трекере <b>'.$tracker.'</b>.';
			else
			{
				$name = ! empty($_POST['name']) ? $_POST['name'] : Sys::getHeader($_POST['url']);
				Topics::add(array('tracker' => $tracker, 'name' => (string)$name, 'path' => (string)$_POST['path'], 'topicId' => $parsed['topicId'],
					'autoRename' => (bool)Sys::strBoolToInt($_POST['update_header']), 'category' => (string)($_POST['category'] ?? '')));
				$return = array('error' => FALSE, 'msg' => 'Тема добавлена для мониторинга.');
			}
		}
		echo json_encode($return);
	}

	//Добавляем сериал для мониторинга
	if ($_POST['action'] == 'serial_add')
	{
		$tracker = (string)$_POST['tracker'];
		$name = trim((string)$_POST['name']);
		$quality = Database::quality($tracker, $_POST['hd']);
		$return = array('error' => TRUE);
		if (Registry::kind($tracker) != 'series')
			$return['msg'] = 'Отсутствует модуль для трекера - <b>'.htmlspecialchars($tracker).'</b>.';
		elseif ( ! Registry::ready($tracker))
			$return['msg'] = 'Вы не можете следить за этим сериалом на трекере - <b>'.$tracker.'</b>, пока не введёте свои учётные данные!';
		elseif ($name === '' || ! Registry::get($tracker)->validName($name))
			$return['msg'] = 'Недопустимое название сериала.';
		elseif (Topics::findSeries($tracker, $name, $quality) !== NULL)
			$return['msg'] = 'Вы уже следите за данным сериалом на этом трекере - <b>'.$tracker.'</b>.';
		else
		{
			Topics::add(array('tracker' => $tracker, 'name' => $name, 'path' => (string)$_POST['path'], 'quality' => $quality, 'category' => (string)($_POST['category'] ?? '')));
			$return = array('error' => FALSE, 'msg' => 'Сериал добавлен для мониторинга.');
		}
		echo json_encode($return);
	}

	//Обновляем отслеживаемый item
	if ($_POST['action'] == 'update')
	{
		$tracker = (string)$_POST['tracker'];
		$fields = array('name' => (string)$_POST['name'], 'path' => (string)$_POST['path'], 'script' => (string)$_POST['script'],
			'paused' => (bool)Sys::strBoolToInt($_POST['pause']));
		if (Sys::strBoolToInt($_POST['reset']))
			$fields += array('updatedAt' => '', 'episode' => '', 'hash' => '');
		$return = array('error' => TRUE, 'msg' => 'Не верная ссылка.');
		if (Registry::kind($tracker) == 'series')
		{
			$fields['quality'] = Database::quality($tracker, $_POST['hd']);
			Topics::update($_POST['id'], $fields);
			$return = array('error' => FALSE, 'msg' => 'Сериал обновлён.');
		}
		elseif (($parsed = Registry::fromUrl((string)$_POST['url'])) !== NULL)
		{
			unset($fields['episode']);
			$fields += array('tracker' => $parsed['tracker'], 'topicId' => $parsed['topicId'],
				'autoRename' => (bool)Sys::strBoolToInt($_POST['update']), 'category' => (string)($_POST['category'] ?? ''));
			Topics::update($_POST['id'], $fields);
			$return = array('error' => FALSE, 'msg' => 'Тема обновлена.');
		}
		echo json_encode($return);
	}

	//Добавляем пользователя для мониторинга
	if ($_POST['action'] == 'user_add')
	{
		$tracker = (string)$_POST['tracker'];
		$return = array('error' => TRUE);
		if ( ! Registry::watchesUsers($tracker))
			$return['msg'] = 'Отсутствует модуль для трекера - <b>'.htmlspecialchars($tracker).'</b>.';
		elseif ( ! Registry::ready($tracker))
			$return['msg'] = 'Вы не можете следить за этим пользователем на трекере - <b>'.$tracker.'</b>, пока не введёте свои учётные данные!';
		elseif ( ! Database::checkUserExist($tracker, $_POST['name']))
			$return['msg'] = 'Вы уже следите за данным пользователем на этом трекере - <b>'.$tracker.'</b>.';
		else
		{
			Database::setUser($tracker, $_POST['name']);
			$return = array('error' => FALSE, 'msg' => 'Пользователь добавлен для мониторинга.');
		}
		echo json_encode($return);
	}

	//Удаляем пользователя из мониторинга и все его темы
	if ($_POST['action'] == 'delete_user')
	{
    	Database::deletUser($_POST['user_id']);
    	$return['error'] = FALSE;
        $return['msg'] = 'Слежение за пользователем удалено.';
        echo json_encode($return);
	}

	//Удаляем тему из буфера
	if ($_POST['action'] == 'delete_from_buffer')
	{
    	Database::deleteFromBuffer($_POST['id']);
    	$return['error'] = FALSE;
        $return['msg'] = 'Тема удалена из буфера.';
        echo json_encode($return);
	}

	//Очищаем весь список тем
	if ($_POST['action'] == 'thremes_clear')
	{
    	Database::thremesClear($_POST['user_id']);
    	$return['error'] = FALSE;
        $return['msg'] = 'Буфер очищен.';
        echo json_encode($return);
	}

	//Перемещаем тему из буфера в мониторинг постоянный
	if ($_POST['action'] == 'transfer_from_buffer')
	{
    	Database::transferFromBuffer($_POST['id']);
    	$return['error'] = FALSE;
        $return['msg'] = 'Тема перенесена из буфера.';
        echo json_encode($return);
	}

	//Помечаем тему для скачивания
	if ($_POST['action'] == 'threme_add')
	{
		$update = Database::updateThremesToDownload($_POST['id']);
		if ($update)
		{
			$return['error'] = FALSE;
			$return['msg'] = 'Тема помечена для закачки.';
		}
		else
			$return['error'] = TRUE;
		echo json_encode($return);
	}

	//Удаляем мониторинг
	if ($_POST['action'] == 'del')
	{
		if ( ! empty($_POST['removeFromClient']))
		{
			$torrent = Database::getTorrent($_POST['id']);
			if (is_array($torrent) && ! empty($torrent[0]['hash']))
				Sys::removeFromClient($torrent[0]['hash']);
		}
		Database::deletItem($_POST['id']);
    	$return['error'] = FALSE;
        $return['msg'] = 'Удалено.';
        echo json_encode($return);
	}

	//Обновляем личные данные
	if ($_POST['action'] == 'update_credentials')
	{
    	if ( ! isset($_POST['passkey']))
    	    $_POST['passkey'] = '';
		//сохранённый пароль в браузер не передаётся: пустое поле означает "не менять"
		$password = (string)($_POST['pass'] ?? '');
		if ($password === '')
		{
			$saved = Database::getCredentials(Registry::byLegacyId($_POST['id']));
			if (is_array($saved))
				$password = $saved['password'];
		}
		Database::setCredentials($_POST['id'], $_POST['log'], $password, $_POST['passkey'], $_POST['address'] ?? NULL);
		if ( ! empty($_POST['cookie']) && ! empty($_POST['tracker']))
		    Database::setCookie($_POST['tracker'], $_POST['cookie']);
    	$return['error'] = FALSE;
        $return['msg'] = 'Данные для трекера обновлены.';
        echo json_encode($return);
	}

    //Обновляем основные настройки
    if ($_POST['action'] == 'update_basic')
	{
        Database::updateSettings('serverAddress', Sys::checkPath($_POST['serverAddress']));
        Database::updateSettings('userAgent', $_POST['userAgent']);
        Database::updateSettings('send', Sys::strBoolToInt($_POST['send']));
        Database::updateSettings('auth', Sys::strBoolToInt($_POST['auth']));
        Database::updateSettings('rss', Sys::strBoolToInt($_POST['rss']));
        Database::updateSettings('autoUpdate', Sys::strBoolToInt($_POST['autoUpdate']));
        Database::updateSettings('debug', Sys::strBoolToInt($_POST['debug']));

        $return['error'] = FALSE;
        $return['msg'] = 'Основные настройки сохранены.';
        echo json_encode($return);
    }

	//Обновляем расширенные настройки
	if ($_POST['action'] == 'update_extended')
	{
		Database::updateSettings('httpTimeout', (int) $_POST['httpTimeout']);
		Database::updateSettings('flaresolverrUrl', trim($_POST['flaresolverrUrl']));

		//прокси для отдельных трекеров, в виде JSON
		$text = trim((string)($_POST['settings'] ?? ''));
		$proxy = $text === '' ? array() : json_decode($text, TRUE);
		if (is_array($proxy))
		{
			Database::setTrackerProxy($proxy);
			$return['error'] = FALSE;
			$return['msg'] = 'Расширенные настройки сохранены.';
		}
		else
		{
			$return['error'] = TRUE;
			$return['msg'] = 'Прокси для трекеров не сохранены: это не корректный JSON.';
		}
		echo json_encode($return);
	}

	//Обновляем настройки уведомлений
	if ($_POST['action'] == 'update_services')
	{
        $notifications = Sys::strBoolToInt($_POST['notifySend']) ? Sys::strBoolToInt($_POST['notifySend']) : false;
        if ($notifications) {
            $setNotify = $_POST['notifyService'];
            Database::updateSettings('sendUpdate', 1);
            Database::updateSettings('sendUpdateService', $setNotify['id']);
            Database::updateAddress('notification', $setNotify['id'], $setNotify['address']);
        } else {
            Database::updateSettings('sendUpdate', 0);
        }

        $warnings = Sys::strBoolToInt($_POST['warnSend']) ? Sys::strBoolToInt($_POST['warnSend']) : false;
        if ($warnings) {
            $setWarn = $_POST['warnService'];
            Database::updateSettings('sendWarning', 1);
            Database::updateSettings('sendWarningService', $setWarn['id']);
            Database::updateAddress('warning', $setWarn['id'], $setWarn['address']);
        } else {
            Database::updateSettings('sendWarning', 0);
        }

        if ($notifications or $warnings) {
            Database::updateSettings('send', 1);
        }

        $return['error'] = FALSE;
        $return['msg'] = 'Настройки сервисов сохранены.';
        echo json_encode($return);
    }


	//Меняем пароль
	if ($_POST['action'] == 'update_auth')
	{
		$pass = password_hash($_POST['pass'], PASSWORD_BCRYPT);
		$q = Database::updateCredentials($pass);
		if ($q)
		{
			$return['error'] = FALSE;
            $return['msg'] = 'Новый пароль установлен.';
		}
		else
		{
			$return['error'] = TRUE;
			$return['msg'] = 'Не удалось сменить пароль!';
		}
		echo json_encode($return);
	}

    //Обновляем настройки прокси
    if ($_POST['action'] == 'update_proxy')
    {
        Database::updateSettings('proxy', Sys::strBoolToInt($_POST['proxy']));
		Database::updateSettings('proxyType', $_POST['proxyType']);
		Database::updateSettings('proxyAddress', $_POST['proxyAddress']);

        $return['error'] = FALSE;
        $return['msg'] = 'Настройки прокси сохранены.';
        echo json_encode($return);
    }

    //Обновляем настройки торрент-клиенты
    if ($_POST['action'] == 'update_torrent')
    {
        Database::updateSettings('useTorrent', Sys::strBoolToInt($_POST['useTorrent']));
        Database::updateSettings('torrentClient', $_POST['torrentClient']);
        Database::updateSettings('torrentAddress', Sys::checkUrl($_POST['torrentAddress']));
        Database::updateSettings('torrentLogin', $_POST['torrentLogin']);
        //сохранённый пароль в браузер не передаётся: пустое поле означает "не менять"
        if (($_POST['torrentPassword'] ?? '') !== '')
            Database::updateSettings('torrentPassword', $_POST['torrentPassword']);
        Database::updateSettings('pathToDownload', Sys::checkPath($_POST['pathToDownload']));
        Database::updateSettings('deleteDistribution', Sys::strBoolToInt($_POST['deleteDistribution']));
        Database::updateSettings('deleteOldFiles', Sys::strBoolToInt($_POST['deleteOldFiles']));
        Database::updateSettings('deleteFilesOnRemove', Sys::strBoolToInt($_POST['deleteFilesOnRemove'] ?? 0));
        Database::updateSettings('qbitCategory', $_POST['qbitCategory'] ?? '');

        $return['error'] = FALSE;
        $return['msg'] = 'Настройки торрент-клиента сохранены.';
        echo json_encode($return);
    }

    //Помечаем новость как прочитанную
	if ($_POST['action'] == 'markNews')
	{
		Database::markNews($_POST['id']);
		return TRUE;
	}

	//Выполняем обновление системы
	if ($_POST['action'] == 'system_update')
	{
		Update::main();
		return TRUE;
	}

    //Генерируем новый токен для API
    if ($_POST['action'] == 'api_token_regenerate')
    {
        $token = bin2hex(random_bytes(32));
        Database::updateSettings('ApiKey', $token);
        $return['error'] = FALSE;
        $return['msg'] = 'Ключ сгенерирован.';
        $return['token'] = $token;
        echo json_encode($return);
    }

	//Очистка ошибок потрекерно
	if ($_POST['action'] == 'clear_warnings')
	{
		if (Database::clearWarnings($_POST['tracker'])) {
            $return['error'] = FALSE;
            $return['msg'] = 'Ошибки трекера очищены.';
        } else {
            $return['error'] = TRUE;
            $return['msg'] = 'Не удалось очистить ошибки трекера.';
        }
        echo json_encode($return);
	}

}

if (isset($_GET['action']))
{
    if ( ! Sys::checkAuth())
        exit();

	//Сортировка вывода торрентов
	if ($_GET['action'] == 'order')
	{
        $by  = !empty($_GET['by']) ? $_GET['by'] : 'name';
        $dir = !empty($_GET['dir']) ? $_GET['dir'] : 'asc';

		if (!in_array($by, ['name', 'date'])) {
            $by = 'name';
        }
		if (!in_array($dir, ['asc', 'desc'])) {
            $dir = 'asc';
        }
		setcookie('order', $by, time()+3600*24*365);
		setcookie('orderDir', $dir, time()+3600*24*365);
		//header('Location: index.php');
        echo json_encode('ok');
	}

    // Single item data
    if ($_GET['action'] == 'item_data') {
        $item = Database::getTorrent($_GET['id'])[0];
        if (!empty($item['torrent_id'])) {
            $item['url'] = Url::href($item['tracker'], $item['torrent_id']);
        }
        $item['reset'] = false;
        echo json_encode($item, JSON_NUMERIC_CHECK);
    }
    
    // Очистка таблицы Temp
    // action.php?action=temp_clear
    if ($_GET['action'] == 'temp_clear') {
        if (Database::clearTemp())
            echo 'Таблица очищена';
    }
}
?>
