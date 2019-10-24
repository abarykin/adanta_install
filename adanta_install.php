<?php
/**
 * Инсталятор CMS Adanta
 *
 * @version   1.004
 */

header('Content-Type: text/html; charset=utf-8');
// сервер обновлений
define('UPDATE_URL', 'http://barykin.com/repository2.0.php');
// корень сайта
define('ROOT', $_SERVER['DOCUMENT_ROOT']);
$_POST = json_decode(file_get_contents('php://input'), true);

/**
 * Реккурсивный парсинг массива файлов
 *
 * @param string $path
 * @param array  $list
 * @return array
 */
function parse_list($path, $list)
{
	$array = array();
	foreach ($list as $key => $value) {
		if (is_array($value)) {
			/** @noinspection SlowArrayOperationsInLoopInspection */
			$array = array_merge($array, parse_list($path . '/' . $key, $value));
		} else {
			$array[] = $path . '/' . $key;
		}
	}

	return $array;
}

/**
 * Проверка существования папки на сервере, в противном случае создание её
 *
 * @param string $folder папка, включает путь от корня
 * @throws \Exception
 */
function is_dir_and_create($folder)
{
	if (!is_dir($folder) && !mkdir($folder) && !is_dir($folder)) {
		throw new \Exception('Не удалось создать папку ' . $folder);
	}
}


/**
 * Распаковка ZIP архива
 *
 * @param string $filename
 * @throws \Exception
 */
function extract_zip($filename)
{
	$path_parts = pathinfo($filename);
	if ($path_parts['extension'] === 'zip') {
		$dir_array = explode('/', substr($filename, 0, -4));
		$dir = '';
		$count_dir = count($dir_array);
		for ($i = 1; $i < $count_dir; $i++) {
			$dir .= '/' . $dir_array[$i];
			is_dir_and_create($dir);
		}

		$zip = new \ZipArchive;
		$res = $zip->open(ROOT . $filename);
		if ($res === true) {
			$zip->extractTo(ROOT . substr($filename, 0, -4) . '/');
			$zip->close();
		}

		// скачиваем info-файл
		$file_info = $path_parts['dirname'] . '/' . $path_parts['filename'] . '.info';
		$content = file_get_contents(UPDATE_URL . '?mode=getfile&filename=' . $file_info);
		file_put_contents(ROOT . $file_info, $content);

		// удаляем zip-файл
		unlink(ROOT . $filename);
	}
}

/**
 * Копирование дефолтных настроек из корня в папку конфигурации
 *
 * @param $file_name
 */
function copy_default_setting($file_name)
{
	$file_name_dest = '';
	if (strpos($file_name, 'default_db') !== false) {
		$file_name_dest = 'db.php';
	}
	if (strpos($file_name, 'default_email') !== false) {
		$file_name_dest = 'email.php';
	}
	if (strpos($file_name, 'default_setting') !== false) {
		$file_name_dest = 'setting.php';
	}
	$file_name_dest = ROOT . '/assets/config/' . $file_name_dest;
	if ($file_name_dest && !file_exists($file_name_dest)) {
		copy($file_name, $file_name_dest);
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (isset($_POST['delete'])) {
		array_map('unlink', glob(ROOT . '/default_*.php'));
		array_map('unlink', glob(ROOT . '/adanta_*.php'));
		@unlink(ROOT . '/file_array.json');
	}

	$result = array();
	if (isset($_POST['step'])) {
		$step = (int)$_POST['step'];
		switch ($step) {
			case 0:
				$result['text'] = 'Версия PHP ' . PHP_VERSION . ' <strong>OK</strong>';
				if (version_compare(PHP_VERSION, '5.4.0', '<=')) {
					header($_SERVER['SERVER_PROTOCOL'] . ' 406 Not Acceptable', true);
					$result['text'] = 'Ваш хостинг должен иметь версию PHP 5.4.0 или выше для установки и работы Adanta CMS.';
				}
				break;

			case 1:
				$result['text'] = 'Расширения PHP для БД <strong>OK</strong>';
				$extensions = array_flip(get_loaded_extensions());
				if (!isset($extensions['mysqli']) && !isset($extensions['PDO'], $extensions['pdo_mysql'])) {
					header($_SERVER['SERVER_PROTOCOL'] . ' 406 Not Acceptable', true);
					$result['text']
						= 'Ваша версия PHP должна иметь поддержку расширений <strong>mysqli</strong> и\или strong>pdo_mysql</strong> для работы с базой данных!';
				}
				break;

			case 2:
				$result['text'] = 'Расширение PHP для обновлений <strong>OK</strong>';
				$extensions = array_flip(get_loaded_extensions());
				if (!isset($extensions['zip'])) {
					header($_SERVER['SERVER_PROTOCOL'] . ' 406 Not Acceptable', true);
					$result['text'] =
						'Ваша версия PHP должна иметь поддержку расширения <strong>zip</strong> для установки CMS и получения обновлений!';
				}
				break;

			case 3:
				$result['text'] = 'Создание директорий <strong>OK</strong>';
				$dir_array = array(
					'/_backup',
					'/_cache',
					'/_cache/ajax',
					'/_cache/page',
					'/_cache/html',
					'/_cache/images',
					'/assets',
					'/assets/config',
					'/classes',
					'/frontend',
					'/uploads',
					'/uploads/images',
					'/vendor'
				);
				try {
					foreach ($dir_array as $dir) {
						is_dir_and_create($dir);
					}
				} catch (\Exception $e) {
					header($_SERVER['SERVER_PROTOCOL'] . ' 406 Not Acceptable', true);
					$result['text'] = 'Не удалось создать директорию ' . $dir . '<br>' . $e->getMessage();
				}
				break;

			case 4:
				$result['text'] = 'Настройка CMS <strong>OK</strong>';
				array_map('copy_default_setting', glob(ROOT . '/default*.php'));
				if (!file_exists(ROOT . '/.htaccess')) {
					$htaccess = '
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule .* index.php?$0 [PT,L,QSA]';
					file_put_contents('.htaccess', $htaccess);
				}
				break;

			case 5:
				$result['text'] = 'Получение списка файлов CMS <strong>OK</strong>';
				try {
					$update_list = file_get_contents(UPDATE_URL . '?mode=getlist');
					$update_array = json_decode($update_list, true);
					$file_array = parse_list('', $update_array);
					file_put_contents('file_array.json', json_encode($file_array, JSON_UNESCAPED_UNICODE));
				} catch (\Exception $e) {
					$result['text'] = 'Не удалось получить список файлов с сервера обновлений.';
					header($_SERVER['SERVER_PROTOCOL'] . ' 406 Not Acceptable', true);
				}
				break;

			case 6:
				$file_array = file_get_contents('file_array.json');
				$file_array = json_decode($file_array, true);
				if (isset($_POST['file'])) {
					$file = $_POST['file'];
					$file_name = $file_array[$file];
					$pathinfo = pathinfo($file_name);
					$dirname = $pathinfo['dirname'];
					is_dir_and_create($dirname);
					$content = file_get_contents(UPDATE_URL . '?mode=getfile&filename=' . $file_name);
					file_put_contents(ROOT . $file_name, $content);
					if ($pathinfo['extension']) {
						extract_zip($file_name);
					}
					$result['text'] = $file_name;
				} else {
					$result['text'] = 'Загрузка файлов <strong class="count_file">0</strong>';
					$result['count'] = count($file_array);
				}
				break;
		}
		if (!isset($_POST['file'])) {
			// небольшая задержка для придания солидности
			usleep(141592);
		}
	}
	echo json_encode($result, false);

	exit;
}
?>
<!DOCTYPE HTML>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<title>Установка CMS Adanta</title>
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" href="https://yastatic.net/bootstrap/3.3.6/css/bootstrap.min.css">
	<!--suppress CssUnusedSymbol -->
	<style>
		body{
			min-width: 320px;
		}

		.jumbotron{
			margin: 0;
		}

		.jumbotron .container .logo{
			float: left;
			margin-right: 50px;
		}

		@keyframes spinner{
			from{
				transform: rotate(0deg);
			}
			to{
				transform: rotate(90deg);
			}
		}

		.jumbotron .container .logo:hover{
			animation-name: spinner;
			animation-timing-function: linear;
			animation-iteration-count: 1;
			animation-duration: 0.5s;
		}

		.jumbotron .container h1{
			color: #337AB7;
			text-align: center;
		}

		.info .container{
			padding: 20px 0;
		}

		.progress-bar{
			-webkit-transition: none !important;
			transition: none !important;
		}

		.btn-install{
			margin: 0 auto;
			display: block;
			font-size: 42px;
			padding: 10px 30px;
		}

		.alert-success{
			margin-bottom: 10px;
			padding: 10px 15px;
		}

		.alert-success strong{
			float: right;
		}

		.delete-install,
		.link-admin{
			margin-bottom: 10px;
			width: 100%;
		}

		@media screen and (min-width: 768px){
			.delete-install{
				margin-right: 10px;
				width: calc(50% - 10px);
			}

			.link-admin{
				margin-left: 10px;
				width: calc(50% - 10px);
			}
		}

		@media screen and (min-width: 992px){
			.jumbotron .container h1{
				text-align: left;
			}

		}
	</style>

	<script src="https://yastatic.net/jquery/2.2.0/jquery.min.js"></script>
	<!--suppress JSUnresolvedVariable, JSUnresolvedFunction, JSUnusedGlobalSymbols -->
	<script>
		var $favicon = 'data:image/png;base64,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';

		if (window.jQuery) {
			$(function () {
				"use strict";
				var $step = 0,
					$count_step = 6,
					$file = 0,
					$count_file = 0,
					$info_block = $('.info .container'),
					$progress_bar = $('<div/>')
						.addClass('progress')
						.append($('<div/>')
							.addClass('progress-bar progress-bar-success progress-bar-striped')),
					$path_install = document.location.pathname;

				$('head')
					.append('<link rel="shortcut icon" type="image/x-icon">')
					.find("link[rel='shortcut icon']")
					.attr('href', $favicon);

				$(document).on("click", ".btn-install", function () {
					$info_block.empty();
					do_step($step);
				});

				$(document).on("click", ".delete-install", function () {
					$.ajax({
						type:        'POST',
						url:         $path_install,
						contentType: 'application/json; charset=utf-8',
						dataType:    'json',
						data:        JSON.stringify({'delete': 1}),
						success:     function () {
							$('.delete-install').addClass('disabled');
						}
					});
				});

				function do_step() {
					if ($step > $count_step) {
						$info_block
							.append('<hr>')
							.append('<div class="alert alert-info"><strong>Установка Adanta CMS успешно завершена.</strong></div>')
							.append('<button class="btn btn-primary delete-install">Удалить установочные файлы</button>')
							.append('<a href="/admin/option" class="btn btn-primary link-admin">Перейти в панель управления CMS</a>');
						return false;
					}
					$.ajax({
						type:        'POST',
						url:         $path_install,
						contentType: 'application/json; charset=utf-8',
						dataType:    'json',
						data:        JSON.stringify({step: $step}),
						success:     function (data) {
							$info_block.append('<div class="alert alert-success">' + data.text + '</div>');
							if (data.count) {
								$count_file = data.count;
								$info_block.append($progress_bar);
								get_file();
							} else {
								$step++;
								do_step();
							}
						},
						error:       function (jqXHR) {
							$info_block.append('<div class="alert alert-danger"> ОШИБКА! ' + jqXHR.responseJSON.text + '</div>');
						}
					});
				}

				function get_file() {
					if ($file >= $count_file) {
						$step++;
						do_step();
					}
					else {
						$.ajax({
							type:        'POST',
							url:         $path_install,
							contentType: 'application/json; charset=utf-8',
							dataType:    'json',
							data:        JSON.stringify({step: $step, file: $file}),
							success:     function (/*data, textStatus, jqXHR*/) {
								$file++;
								$('.count_file').html($file);
								$('.progress-bar').css({width: ($file / $count_file * 100) + '%'});
								get_file();
							},
							error:       function (jqXHR/*, textStatus, errorThrown*/) {
								$info_block.append('<div class="alert alert-danger"> ОШИБКА! ' + jqXHR.responseJSON.text + '</div>');
							}
						});
					}
				}
			});
		}
		else {
			document.write('Ошибка установки - библитека jQuery недоступна! Проверьте интернет-соединение.')
		}
	</script>
</head>
<body>
<div class="jumbotron">
	<div class="container">
		<svg class="logo hidden-xs hidden-sm" width="175" height="175" baseProfile="full" viewBox="0 0 1000 1000"
		     xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
			<defs>
				<path id="a" d="M1000 0v755h-99V239l-71 71H690" fill="#337AB7"/>
			</defs>
			<use xlink:href="#a" transform="rotate(22.5 1000 0)"/>
			<use xlink:href="#a" transform="rotate(-67.5 500 748.303)"/>
			<use xlink:href="#a" transform="rotate(-157.5 599.456 599.456)"/>
			<use xlink:href="#a" transform="rotate(112.5 665.91 500)"/>
		</svg>
		<h1>Установка Adanta&nbsp;CMS</h1>
	</div>
</div>
<div class="info">
	<div class="container">
		<button class="btn btn-primary btn-install">Установить</button>
	</div>
</div>
</body>
</html>