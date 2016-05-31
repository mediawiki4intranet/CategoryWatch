<?php

/**
 * CategoryWatch extension
 * - Extends watchlist functionality to include notification about membership changes of watched categories
 *
 * See http://www.mediawiki.org/Extension:CategoryWatch for installation and usage details
 * See http://www.organicdesign.co.nz/Extension_talk:CategoryWatch for development notes and disucssion
 *
 * @file
 * @ingroup Extensions
 * @author Aran Dunkley [http://www.organicdesign.co.nz/nad User:Nad], [http://wiki.4intra.net/User:VitaliyFilippov Vitaliy Filippov]
 * @copyright © 2008 Aran Dunkley, © 2016+ Vitaliy Filippov
 * @licence GNU General Public Licence 2.0 or later
 */

if (!defined('MEDIAWIKI'))
	die('Not an entry point.');

define('CATEGORYWATCH_VERSION', '1.3, 2016-05-31');

# Whether or not to also send notificaton to the person who made the change
$wgCategoryWatchNotifyEditor = true;

# Set this to give every user a unique category that they're automatically watching
# - the format of the category name is defined on the "categorywatch-autocat" localisation message
$wgCategoryWatchUseAutoCat = false;

# Set this to make the categorisation work by realname instead of username
$wgCategoryWatchUseAutoCatRealName = false;

$wgExtensionFunctions[] = 'wfSetupCategoryWatch';
$wgExtensionCredits['other'][] = array(
	'path'           => __FILE__,
	'name'           => 'CategoryWatch',
	'author'         => '[http://www.organicdesign.co.nz/User:Nad User:Nad], [http://wiki.4intra.net/User:VitaliyFilippov Vitaliy Filippov]',
	'descriptionmsg' => 'categorywatch-desc',
	'url'            => 'https://www.mediawiki.org/wiki/Extension:CategoryWatch',
	'version'        => CATEGORYWATCH_VERSION,
);

$wgExtensionMessagesFiles['CategoryWatch'] = dirname(__FILE__) . '/CategoryWatch.i18n.php';

class CategoryWatch
{
	function __construct()
	{
		global $wgHooks;
		$wgHooks['ArticleSave'][] = $this;
		$wgHooks['ArticleSaveComplete'][] = $this;
		$wgHooks['ArticleEditUpdates'][] = $this;
	}

	/**
	 * Enforce auto-watch categories
	 */
	function onArticleSave(&$article, &$user, $text)
	{
		global $wgCategoryWatchUseAutoCat, $wgCategoryWatchUseAutoCatRealName;

		// If using the automatically watched category feature, ensure that all users are watching it
		if ($wgCategoryWatchUseAutoCat)
		{
			$dbr = wfGetDB(DB_SLAVE);

			// Check if the user is not watching the autocat
			$userid = $user->getId();
			$like = str_replace(' ', '_', trim(wfMsg('categorywatch-autocat', '')));
			$utbl = $dbr->tableName('user');
			$wtbl = $dbr->tableName('watchlist');
			$sql = "SELECT user_id FROM $utbl LEFT JOIN $wtbl ON user_id=wl_user AND wl_title LIKE '%$like%' WHERE user_id=$userid wl_user IS NULL";
			$res = $dbr->query($sql, __METHOD__);

			// Insert an entry into watchlist for each
			if ($row = $dbr->fetchRow($res))
			{
				$name = $wgCategoryWatchUseAutoCatRealName ? $user->getRealName() : $user->getName();
				$wl_title = str_replace(' ', '_', wfMsg('categorywatch-autocat', $name));
				$dbr->insert($wtbl, array('wl_user' => $row[0], 'wl_namespace' => NS_CATEGORY, 'wl_title' => $wl_title));
			}
			$dbr->freeResult($res);
		}
		return true;
	}

	/**
	 * Get a list of categories before and after article is updated
	 */
	function onArticleEditUpdates(&$article, &$editInfo, $changed)
	{
		$this->before = array();
		$id = $article->getID();
		if ($id)
		{
			// FIXME: Try to avoid extra DB query here (categorylinks is queried in LinksUpdate)
			$dbr = wfGetDB(DB_SLAVE);
			$res = $dbr->select('categorylinks', 'cl_to', array('cl_from' => $id), __METHOD__, array('ORDER BY' => 'cl_sortkey'));
			while ($row = $dbr->fetchRow($res))
				$this->before[] = $row[0];
			$dbr->freeResult($res);
		}
		$this->after = $editInfo->output->getCategoryLinks();
		return true;
	}

	/**
	 * Find changes in categorisation and send messages to watching users
	 */
	function onArticleSaveComplete(&$article, &$user, $text, $summary, $medit)
	{
		// Get list of added and removed cats
		$add = array_diff($this->after, $this->before);
		$sub = array_diff($this->before, $this->after);

		// Notify watchers of each cat about the addition or removal of this article
		if ($add || $sub)
		{
			$page = $article->getTitle();
			$pagename = $page->getPrefixedText();
			$pageurl  = $page->getFullUrl();

			if (count($add) == 1 && count($sub) == 1)
			{
				$add = array_shift($add);
				$sub = array_shift($sub);
				$title = Title::newFromText($add, NS_CATEGORY);
				$subtitle = Title::newFromText($sub, NS_CATEGORY);
				$message = wfMsg('categorywatch-catmovein', "$pagename ($pageurl)", $this->friendlyCat($add), $this->friendlyCat($sub));
				$messageHtml = wfMsgNoTrans(
					'categorywatch-catmovein',
					'<a href="'.$page->getFullUrl().'">'.htmlspecialchars($page).'</a>',
					'<a href="'.$title->getFullUrl().'">'.htmlspecialchars($add).'</a>',
					'<a href="'.$subtitle->getFullUrl().'">'.htmlspecialchars($sub).'</a>',
					htmlspecialchars($user->getName())
				);
				$this->notifyWatchers($title, $user, $message, $messageHtml, $summary, $medit);
			}
			else
			{
				foreach ($add as $cat)
				{
					$title = Title::newFromText($cat, NS_CATEGORY);
					$message = wfMsg('categorywatch-catadd', "$pagename ($pageurl)", $this->friendlyCat($cat), $user->getName());
					$messageHtml = wfMsgNoTrans(
						'categorywatch-catadd',
						'<a href="'.$page->getFullUrl().'">'.htmlspecialchars($page).'</a>',
						'<a href="'.$title->getFullUrl().'">'.htmlspecialchars($cat).'</a>',
						htmlspecialchars($user->getName())
					);
					$this->notifyWatchers($title, $user, $message, $messageHtml, $summary, $medit);
				}
			}
		}
		return true;
	}

	/**
	 * Return "Category:Cat (URL)" from "Cat"
	 */
	function friendlyCat($cat)
	{
		$cat = Title::newFromText($cat, NS_CATEGORY);
		$catname = $cat->getPrefixedText();
		$caturl = $cat->getFullUrl();
		return "$catname ($caturl)";
	}

	function notifyWatchers($title, $editor, $message, $messageHtml, $summary, $medit)
	{
		global $wgLang, $wgCategoryWatchNotifyEditor, $wgEnotifUseRealName;

		// Get list of users watching this category
		$dbr = wfGetDB(DB_SLAVE);
		$conds = array('wl_title' => $title->getDBkey(), 'wl_namespace' => $title->getNamespace());
		if (!$wgCategoryWatchNotifyEditor)
			$conds[] = 'wl_user <> ' . intval($editor->getId());
		$res = $dbr->select('watchlist', array('wl_user'), $conds, __METHOD__);

		$commonKeys = NULL;
		while ($row = $dbr->fetchRow($res))
		{
			$watchingUser = User::newFromId($row[0]);
			if ($watchingUser->getOption('enotifwatchlistpages') && $watchingUser->isEmailConfirmed())
			{
				// Wrap message with common body and send to each watcher
				if ($commonKeys === NULL)
				{
					// $wgPasswordSenderName was introduced only in MW 1.17
					global $wgEnotifRevealEditorAddress, $wgPasswordSender, $wgPasswordSenderName,
						$wgNoReplyAddress, $wgEnotifFromEditor;
					$adminAddress = new MailAddress($wgPasswordSender,
						isset($wgPasswordSenderName) ? $wgPasswordSenderName : 'WikiAdmin');
					$editorAddress = new MailAddress($editor);

					// Reveal the page editor's address as REPLY-TO address only if
					// the user has not opted-out and the option is enabled at the
					// global configuration level.
					if ($wgEnotifRevealEditorAddress && $editor->getEmail() != '' &&
						$editor->getOption('enotifrevealaddr'))
					{
						if ($wgEnotifFromEditor)
							$from = $editorAddress;
						else
						{
							$from = $adminAddress;
							$replyto = $editorAddress;
						}
					}
					else
					{
						$from = $adminAddress;
						$replyto = new MailAddress($wgNoReplyAddress);
					}

					// Define keys for body message
					$userPage = $editor->getUserPage();
					$page = $title->getPrefixedText();
					$commonKeys = array(
						'$PAGEINTRO'        => $message,
						'$NOFURTHERNOTICE'  => '',
						'$UNWATCHURL'       => $title->getCanonicalURL('action=unwatch'),
						'$NEWPAGE'          => '',
						'$PAGETITLE'        => $page,
						'$CHANGEDORCREATED' => wfMsgForContent('changed'),
						'$PAGETITLE_URL'    => $title->getFullUrl(),
						'$PAGEEDITOR_WIKI'  => $userPage->getFullUrl(),
						'$PAGESUMMARY'      => ($summary ? $summary : ' - '),
						'$PAGEMINOREDIT'    => ($medit ? wfMsg('minoredit') : ''),
						'$OLDID'            => '',
						'$HELPPAGE'         => wfExpandUrl(
							Skin::makeInternalOrExternalUrl(wfMessage('helppage')->inContentLanguage()->text())
						)
					);
					$subject = wfMsg('categorywatch-emailsubject', $page);
					if ($editor->isAnon())
					{
						$utext = wfMsgForContent('enotif_anon_editor', $editor->getName());
						$subject = str_replace('$PAGEEDITOR', $utext, $subject);
						$commonKeys['$PAGEEDITOR'] = $utext;
						$commonKeys['$PAGEEDITOR_EMAIL'] = wfMsgForContent('noemailtitle');
					}
					else
					{
						$subject = str_replace('$PAGEEDITOR', $editor->getName(), $subject);
						$commonKeys['$PAGEEDITOR'] = $editor->getName();
						$emailPage = SpecialPage::getSafeTitleFor('Emailuser', $editor->getName());
						$commonKeys['$PAGEEDITOR_EMAIL'] = $emailPage->getFullUrl();
					}
				}

				// Replace keys, wrap text and send
				$name = $wgEnotifUseRealName ? $watchingUser->getRealName() : $watchingUser->getName();
				$timecorrection = $watchingUser->getOption('timecorrection');
				$editdate = $wgLang->timeanddate(wfTimestampNow(), true, false, $timecorrection);
				$keys = $commonKeys + array(
					'$WATCHINGUSERNAME' => $name,
					'$PAGEEDITDATE' => $editdate,
				);
				$body = wfMsgForContent('enotif_body');
				$body = strtr($body, $keys);
				$body = wordwrap($body, 72);
				$to = new MailAddress($watchingUser);

				// Support HTML e-mail (Mediawiki4Intranet 1.26 patch)
				$bodyHtml = wfMessage('enotif_body_html');
				if ($bodyHtml->exists())
				{
					foreach ($keys as &$k)
						$k = htmlspecialchars($k);
					$keys['$DIFF'] = '';
					$keys['$PAGEINTRO'] = $messageHtml;
					$bodyHtml = $bodyHtml->inContentLanguage()->plain();
					$bodyHtml = strtr($bodyHtml, $keys);
					$bodyHtml = MessageCache::singleton()->transform($bodyHtml, false, null, $title);
					$body = array(
						'text' => $body,
						'html' => $bodyHtml,
					);
				}

				UserMailer::send($to, $from, $subject, $body, $replyto);
			}
		}

		$dbr->freeResult($res);
	}

	/**
	 * Needed in some versions to prevent Special:Version from breaking
	 */
	function __toString() { return __CLASS__; }
}

function wfSetupCategoryWatch()
{
	global $wgCategoryWatch;

	// Instantiate the CategoryWatch singleton now that the environment is prepared
	$wgCategoryWatch = new CategoryWatch();
}
