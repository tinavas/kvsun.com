<?php
namespace KVSun;

use \shgysk8zer0\Core as Core;
use \shgysk8zer0\DOM as DOM;
use \shgysk8zer0\Core_API\Abstracts\HTTPStatusCodes as HTTP;

if (in_array(PHP_SAPI, ['cli', 'cli-server'])) {
	require_once __DIR__ . DIRECTORY_SEPARATOR . 'autoloader.php';
}

$header  = Core\Headers::getInstance();

if ($header->accept === 'application/json') {
	$resp = Core\JSON_Response::getInstance();
	if (array_key_exists('url', $_GET)) {
		$header->content_type = 'application/json';
		$url = new Core\URL($_GET['url']);
		$path = explode('/', trim($url->path));
		$path = array_filter($path);
		$path = array_values($path);

		if (empty($path)) {
			// This would be a request for home
			// $categories = \KVSun\get_categories();
			$page = new \KVSun\KVSAPI\Home(Core\PDO::load(DB_CREDS), "$url", \KVSun\get_categories('url'));
		} elseif (count($path) === 1) {
			$page = new \KVSun\KVSAPI\Category(Core\PDO::load(DB_CREDS), "$url");
		} else {
			$page = new \KVSun\KVSAPI\Article(Core\PDO::load(DB_CREDS), "$url");
		}

		Core\Console::info($path)->sendLogHeader();
		exit(json_encode($page));
	} elseif (array_key_exists('form', $_REQUEST) and is_array($_REQUEST[$_REQUEST['form']])) {
		require_once COMPONENTS . 'handlers' . DIRECTORY_SEPARATOR . 'form.php';
	} elseif (array_key_exists('datalist', $_GET)) {
		switch($_GET['datalist']) {
			case 'categories':
				$pdo = Core\PDO::load(\KVSun\DB_CREDS);
				$cats = $pdo('SELECT `name` FROM `categories`');
				Core\Console::table($cats)->sendLogHeader();
				$doc = new \DOMDocument();
				$doc->appendChild($doc->createElement('datalist'));
				$doc->documentElement->setAttribute('id', 'categories');
				foreach ($cats as $cat) {
					$item = $doc->documentElement->appendChild($doc->createElement('option'));
					$item->setAttribute('value', $cat->name);
				}

				$resp->append('body', $doc->saveHTML($doc->documentElement));
				break;

			case 'author_list':
				$pdo = Core\PDO::load(DB_CREDS);
				$stm = $pdo->prepare('SELECT `name`
					FROM `user_data`
					JOIN `subscribers` ON `subscribers`.`id` = `user_data`.`id`
					WHERE `subscribers`.`status` <= :role;'
				);
				$stm->role = array_search('freelancer', USER_ROLES);
				$authors = $stm->execute()->getResults();
				$authors = array_map(function(\stdClass $author): String
				{
					return $author->name;
				}, $authors);

				$datalist = make_datalist('author_list', $authors);

				$resp->append('body', $datalist)->send();
				break;

			default:
				trigger_error("Request for unhandled list: {$_GET['list']}");
				header('Content-Type: application/json');
				exit('{}');
		}
	} elseif (array_key_exists('load_menu', $_GET)) {
		$menu = COMPONENTS . 'menus' . DIRECTORY_SEPARATOR . $_GET['load_menu'] . '.html';
		if (@file_exists($menu)) {
			$resp->append('body', file_get_contents($menu));
		} else {
			$resp->notify('Request for menu', $_GET['load_menu']);
		}
	} elseif (array_key_exists('load_form', $_REQUEST)) {
		// All HTML forms in forms/ should be considered publicly available
		if (@file_exists("./components/forms/{$_REQUEST['load_form']}.html")) {
			$form = file_get_contents("./components/forms/{$_REQUEST['load_form']}.html");
			$dom = new DOM\HTML();
			$dialog = $dom->body->append('dialog', null, [
				'id' => "{$_REQUEST['load_form']}-dialog",
			]);
			$dialog->append('button', null, [
				'type'        => 'button',
				'data-delete' => "#{$dialog->id}",
			]);
			$dialog->append('br');
			$dialog->importHTML($form);
			$resp->append('body', "{$dialog}");
			$resp->showModal("#{$dialog->id}");
			$resp->send();
		}
		switch($_REQUEST['load_form']) {
			case 'update-user':
				if (!\KVSUn\check_role('guest')) {
					$resp->notify('You must login for that', 'Cannot update data before logging in.');
					$resp->showModal('#login-dialog');
					$resp->send();
				}
				$dialog = user_update_form(restore_login());
				$resp->append('body', "$dialog");
				$resp->showModal("#{$dialog->id}");
				$resp->send();
				break;

			case 'ccform':
				$user = restore_login();
				if (!isset($user->status)) {
					$resp->notify(
						'You must be logged in for that',
						'You will need to login to have an account to update'
					);

					$resp->showModal('#login-dialog');
					$resp->send();
				} elseif (check_role('subscriber') and ! DEBUG) {
					$resp->notify(
						'You do not need to subscribe',
						"Your subscription has not yet expired"
					)->send();
				}

				$dom = new \shgysk8zer0\DOM\HTML();
				$dialog = $dom->body->append('dialog', null, [
					'id' => 'ccform-dialog'
				]);
				$dialog->append('button', null, [
					'type' => 'button',
					'data-delete' => "#{$dialog->id}",
				]);
				\KVSun\make_cc_form($dialog);
				$resp->append('body', $dialog);
				$resp->showModal("#{$dialog->id}");
				break;

			case 'moderate':
				if (\KVSun\check_role('editor')) {
					try {
						$comments = \KVSun\get_comments();
						$doc = new DOM\HTML;
						$dialog = $doc->body->append('dialog', null, [
							'id' => 'comment-moderator',
						]);
						$dialog->append('button', null, [
							'data-delete' => "#{$dialog->id}",
						]);
						$dialog->append('hr');
						$form = $dialog->append('form', null, [
							'name' => 'comment-moderator-form',
							'action' => \KVSun\DOMAIN . 'api.php',
							'method' => 'POST,'
						]);
						$table = $form->append('table', null, [
							'border' => 1,
						]);
						$thead = $table->append('thead');
						$tbody = $table->append('tbody');
						$tfoot = $table->append('tfoot');
						$tr = $thead->append('tr');
						foreach ([
							'User',
							'Date',
							'Category',
							'Article',
							'Comment',
							'Approved',
							'Delete comment'
						] as $key) {
							$tr->append('th', $key);
						}
						foreach ($comments as $comment) {
							$tr = $tbody->append('tr', null, [
								'id' => "moderate-comments-row-{$comment->ID}",
							]);
							$tr->append('td')->append('a', $comment->name, [
								'href' => "mailto:{$comment->email}",
							]);
							$tr->append('td', (new \DateTime($comment->created))->format('D, M jS, Y @ g:i:s A'));
							$tr->append('td', $comment->category);
							$tr->append('td')->append('a', $comment->Article, [
								'href' => \KVSun\DOMAIN . "{$comment->catURL}/{$comment->postURL}#comment-{$comment->ID}",
								'target' => '_blank',
							]);
							$tr->append('td')->append('blockquote', $comment->comment);
							$approved = $tr->append('td')->append('input', null, [
								'type' => 'checkbox',
								'name' => "{$form->name}[approved][{$comment->ID}]"
							]);
							$tr->append('td')->append('button', 'X', [
								'data-request' => "delete-comment={$comment->ID}",
								'data-confirm' => 'Are you sure you want to delete this comment?',
							]);
							if ($comment->approved) {
								$approved->checked = null;
							}
						}
						$resp->append('body', "$dialog");
						$resp->showModal("#{$dialog->id}");
					} catch (\Throwable $e) {
						Core\Console::error($e);
						$resp->notify('There was an error', $e->getMessage());
					}
				}
				break;

			default:
				trigger_error("Request for unhandled form, {$_REQUEST['load_form']}");
				$resp->notify(
					'Request for unknown form',
					'Please contact us to report this problem.'
				);
				$resp->send();
		}
	} elseif(array_key_exists('upload', $_FILES)) {
		if (! check_role('editor')) {
			trigger_error('Unauthorized upload attempted');
			http_response_code(HTTP::UNAUTHORIZED);
			exit('{}');
		}
		$file = new \shgysk8zer0\Core\UploadFile('upload');
		if (in_array($file->type, ['image/jpeg', 'image/png', 'image/svg+xml', 'image/gif'])) {
			if ($file->saveTo('images', 'uploads', date('Y'), date('m'))) {
				header('Content-Type: application/json');
				exit($file);
			} else {
				$resp->notify('Failed', 'Could not save uploaded file.');
			}
		} else {
			throw new \Exception("{$file->name} has a type of {$file->type}, which is not allowed.");
		}
	} elseif (array_key_exists('action', $_REQUEST)) {
		switch($_REQUEST['action']) {
			case 'logout':
				Core\Listener::logout(restore_login());
				break;
		}
	} elseif (array_key_exists('delete-comment', $_GET)) {
		if (!\KVSun\check_role('editor')) {
			$resp->notify(
				"I'm afraid I can't let you do that, Dave",
				'You are not authorized to moderate comments.',
				'/images/octicons/lib/svg/alert.svg'
			);
		} elseif (\KVSun\delete_comments($_GET['delete-comment'])) {
			$resp->notify('Comment deleted');
			$resp->remove("#moderate-comments-row-{$_GET['delete-comment']}");
		} else {
			$resp->notify(
				'Unable to delete comment',
				'Check error log.',
				'/images/octicons/lib/svg/bug.svg'
			);
		}
	} else {
		$resp->notify('Invalid request', 'See console for details.', DOMAIN . 'images/sun-icons/128.png');
		Core\Console::info($_REQUEST);
	}
	if (check_role('admin') or DEBUG) {
		Core\Console::getInstance()->sendLogHeader();
	}
	$resp->send();
	exit();
}  elseif(array_key_exists('url', $_GET)) {
	$url = new Core\URL($_GET['url']);
	if ($url->host === $_SERVER['SERVER_NAME']) {
		$header->location = "{$url}";
		http_response_code(HTTP::SEE_OTHER);
	} else {
		http_response_code(HTTP::BAD_REQUEST);
	}
} else {
	http_response_code(HTTP::BAD_REQUEST);
	$header->content_type = 'application/json';
	exit('{}');
}
