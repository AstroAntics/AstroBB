<?php
/**
*
* This file is part of the phpBB Forum Software package.
*
* @copyright (c) phpBB Limited <https://www.phpbb.com>
* @license GNU General Public License, version 2 (GPL-2.0)
*
* For full copyright and license information, please see
* the docs/CREDITS.txt file.
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* Compose private message
* Called from ucp_pm with mode == 'compose'
*/
function compose_pm($id, $mode, $action, $user_folders = array())
{
	global $template, $db, $auth, $user, $cache;
	global $phpbb_root_path, $phpEx, $config, $language;
	global $request, $phpbb_dispatcher, $phpbb_container;

	// Damn php and globals - i know, this is horrible
	// Needed for handle_message_list_actions()
	global $refresh, $submit, $preview;

	if (!function_exists('generate_smilies'))
	{
		include($phpbb_root_path . 'includes/functions_posting.' . $phpEx);
	}

	if (!function_exists('display_custom_bbcodes'))
	{
		include($phpbb_root_path . 'includes/functions_display.' . $phpEx);
	}

	if (!class_exists('parse_message'))
	{
		include($phpbb_root_path . 'includes/message_parser.' . $phpEx);
	}

	if (!$action)
	{
		$action = 'post';
	}
	add_form_key('ucp_pm_compose');

	// Grab only parameters needed here
	$to_user_id		= $request->variable('u', 0);
	$to_group_id	= $request->variable('g', 0);
	$msg_id			= $request->variable('p', 0);
	$draft_id		= $request->variable('d', 0);

	// Reply to all triggered (quote/reply)
	$reply_to_all	= $request->variable('reply_to_all', 0);

	$address_list	= $request->variable('address_list', array('' => array(0 => '')));

	$preview	= (isset($_POST['preview'])) ? true : false;
	$save		= (isset($_POST['save'])) ? true : false;
	$load		= (isset($_POST['load'])) ? true : false;
	$cancel		= (isset($_POST['cancel']) && !isset($_POST['save'])) ? true : false;
	$delete		= (isset($_POST['delete'])) ? true : false;

	$remove_u	= (isset($_REQUEST['remove_u'])) ? true : false;
	$remove_g	= (isset($_REQUEST['remove_g'])) ? true : false;
	$add_to		= (isset($_REQUEST['add_to'])) ? true : false;
	$add_bcc	= (isset($_REQUEST['add_bcc'])) ? true : false;

	$refresh	= isset($_POST['add_file']) || isset($_POST['delete_file']) || $save || $load
		|| $remove_u || $remove_g || $add_to || $add_bcc;
	$submit = $request->is_set_post('post') && !$refresh && !$preview;

	$action		= ($delete && !$preview && !$refresh && $submit) ? 'delete' : $action;
	$select_single = ($config['allow_mass_pm'] && $auth->acl_get('u_masspm')) ? false : true;

	$error = array();
	$current_time = time();

	/** @var \phpbb\group\helper $group_helper */
	$group_helper = $phpbb_container->get('group_helper');

	// Was cancel pressed? If so then redirect to the appropriate page
	if ($cancel)
	{
		if ($msg_id)
		{
			redirect(append_sid("{$phpbb_root_path}ucp.$phpEx", 'i=pm&amp;mode=view&amp;action=view_message&amp;p=' . $msg_id));
		}
		redirect(append_sid("{$phpbb_root_path}ucp.$phpEx", 'i=pm'));
	}

	// Since viewtopic.php language entries are used in several modes,
	// we include the language file here
	$user->add_lang('viewtopic');

	/**
	* Modify the default vars before composing a PM
	*
	* @event core.ucp_pm_compose_modify_data
	* @var	int		msg_id					post_id in the page request
	* @var	int		to_user_id				The id of whom the message is to
	* @var	int		to_group_id				The id of the group the message is to
	* @var	bool	submit					Whether the form has been submitted
	* @var	bool	preview					Whether the user is previewing the PM or not
	* @var	string	action					One of: post, reply, quote, forward, quotepost, edit, delete, smilies
	* @var	bool	delete					Whether the user is deleting the PM
	* @var	int		reply_to_all			Value of reply_to_all request variable.
	* @since 3.1.4-RC1
	*/
	$vars = array(
		'msg_id',
		'to_user_id',
		'to_group_id',
		'submit',
		'preview',
		'action',
		'delete',
		'reply_to_all',
	);
	extract($phpbb_dispatcher->trigger_event('core.ucp_pm_compose_modify_data', compact($vars)));

	// Output PM_TO box if message composing
	if ($action != 'edit')
	{
		// Add groups to PM box
		if ($config['allow_mass_pm'] && $auth->acl_get('u_masspm_group'))
		{
			$sql = 'SELECT g.group_id, g.group_name, g.group_type, g.group_colour
				FROM ' . GROUPS_TABLE . ' g';

			if (!$auth->acl_gets('a_group', 'a_groupadd', 'a_groupdel'))
			{
				$sql .= ' LEFT JOIN ' . USER_GROUP_TABLE . ' ug
					ON (
						g.group_id = ug.group_id
						AND ug.user_id = ' . $user->data['user_id'] . '
						AND ug.user_pending = 0
					)
					WHERE (g.group_type <> ' . GROUP_HIDDEN . ' OR ug.user_id = ' . $user->data['user_id'] . ')';
			}

			$sql .= ($auth->acl_gets('a_group', 'a_groupadd', 'a_groupdel')) ? ' WHERE ' : ' AND ';

			$sql .= 'g.group_receive_pm = 1
				ORDER BY g.group_type DESC, g.group_name ASC';
			$result = $db->sql_query($sql);

			$group_options = '';
			while ($row = $db->sql_fetchrow($result))
			{
				$group_options .= '<option' . (($row['group_type'] == GROUP_SPECIAL) ? ' class="sep"' : '') . ' value="' . $row['group_id'] . '"' . ($row['group_colour'] ? ' style="color: #' . $row['group_colour'] . '"' : '') . '>' . $group_helper->get_name($row['group_name']) . '</option>';
			}
			$db->sql_freeresult($result);
		}

		$template->assign_vars(array(
			'S_SHOW_PM_BOX'		=> true,
			'S_ALLOW_MASS_PM'	=> ($config['allow_mass_pm'] && $auth->acl_get('u_masspm')) ? true : false,
			'S_GROUP_OPTIONS'	=> ($config['allow_mass_pm'] && $auth->acl_get('u_masspm_group')) ? $group_options : '',
			'U_FIND_USERNAME'	=> append_sid("{$phpbb_root_path}memberlist.$phpEx", "mode=searchuser&amp;form=postform&amp;field=username_list&amp;select_single=" . (int) $select_single),
		));
	}

	$sql = '';
	$folder_id = 0;

	// What is all this following SQL for? Well, we need to know
	// some basic information in all cases before we do anything.
	switch ($action)
	{
		case 'post':
			if (!$auth->acl_get('u_sendpm'))
			{
				send_status_line(403, 'Forbidden');
				trigger_error('NO_AUTH_SEND_MESSAGE');
			}
		break;

		case 'reply':
		case 'quote':
		case 'forward':
		case 'quotepost':
			if (!$msg_id)
			{
				trigger_error('NO_MESSAGE');
			}

			if (!$auth->acl_get('u_sendpm'))
			{
				send_status_line(403, 'Forbidden');
				trigger_error('NO_AUTH_SEND_MESSAGE');
			}

			if ($action == 'quotepost')
			{
				$sql = 'SELECT p.post_id as msg_id, p.forum_id, p.post_text as message_text, p.poster_id as author_id, p.post_time as message_time, p.bbcode_bitfield, p.bbcode_uid, p.enable_sig, p.enable_smilies, p.enable_magic_url, t.topic_title as message_subject, u.username as quote_username
					FROM ' . POSTS_TABLE . ' p, ' . TOPICS_TABLE . ' t, ' . USERS_TABLE . " u
					WHERE p.post_id = $msg_id
						AND t.topic_id = p.topic_id
						AND u.user_id = p.poster_id";
			}
			else
			{
				$sql = 'SELECT t.folder_id, p.*, u.username as quote_username
					FROM ' . PRIVMSGS_TO_TABLE . ' t, ' . PRIVMSGS_TABLE . ' p, ' . USERS_TABLE . ' u
					WHERE t.user_id = ' . $user->data['user_id'] . "
						AND p.author_id = u.user_id
						AND t.msg_id = p.msg_id
						AND p.msg_id = $msg_id";
			}
		break;

		case 'edit':
			if (!$msg_id)
			{
				trigger_error('NO_MESSAGE');
			}

			// check for outbox (not read) status, we do not allow editing if one user already having the message
			$sql = 'SELECT p.*, t.folder_id
				FROM ' . PRIVMSGS_TO_TABLE . ' t, ' . PRIVMSGS_TABLE . ' p
				WHERE t.user_id = ' . $user->data['user_id'] . '
					AND t.folder_id = ' . PRIVMSGS_OUTBOX . "
					AND t.msg_id = $msg_id
					AND t.msg_id = p.msg_id";
		break;

		case 'delete':
			if (!$auth->acl_get('u_pm_delete'))
			{
				send_status_line(403, 'Forbidden');
				trigger_error('NO_AUTH_DELETE_MESSAGE');
			}

			if (!$msg_id)
			{
				trigger_error('NO_MESSAGE');
			}

			$sql = 'SELECT msg_id, pm_unread, pm_new, author_id, folder_id
				FROM ' . PRIVMSGS_TO_TABLE . '
				WHERE user_id = ' . $user->data['user_id'] . "
					AND msg_id = $msg_id";
		break;

		case 'smilies':
			generate_smilies('window', 0);
		break;

		default:
			trigger_error('NO_ACTION_MODE', E_USER_ERROR);
		break;
	}

	if ($action == 'forward' && (!$config['forward_pm'] || !$auth->acl_get('u_pm_forward')))
	{
		send_status_line(403, 'Forbidden');
		trigger_error('NO_AUTH_FORWARD_MESSAGE');
	}

	if ($action == 'edit' && !$auth->acl_get('u_pm_edit'))
	{
		send_status_line(403, 'Forbidden');
		trigger_error('NO_AUTH_EDIT_MESSAGE');
	}

	if ($sql)
	{
		/**
		* Alter sql query to get message for user to write the PM
		*
		* @event core.ucp_pm_compose_compose_pm_basic_info_query_before
		* @var	string	sql						String with the query to be executed
		* @var	int		msg_id					topic_id in the page request
		* @var	int		to_user_id				The id of whom the message is to
		* @var	int		to_group_id				The id of the group whom the message is to
		* @var	bool	submit					Whether the user is sending the PM or not
		* @var	bool	preview					Whether the user is previewing the PM or not
		* @var	string	action					One of: post, reply, quote, forward, quotepost, edit, delete, smilies
		* @var	bool	delete					Whether the user is deleting the PM
		* @var	int		reply_to_all			Value of reply_to_all request variable.
		* @since 3.1.0-RC5
		* @changed 3.2.0-a1 Removed undefined variables
		*/
		$vars = array(
			'sql',
			'msg_id',
			'to_user_id',
			'to_group_id',
			'submit',
			'preview',
			'action',
			'delete',
			'reply_to_all',
		);
		extract($phpbb_dispatcher->trigger_event('core.ucp_pm_compose_compose_pm_basic_info_query_before', compact($vars)));

		$result = $db->sql_query($sql);
		$post = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		/**
		* Alter the row of the post being quoted when composing a private message
		*
		* @event core.ucp_pm_compose_compose_pm_basic_info_query_after
		* @var	array	post			Array with data of the post being quoted
		* @var	int		msg_id			topic_id in the page request
		* @var	int		to_user_id		The id of whom the message is to
		* @var	int		to_group_id		The id of the group whom the message is to
		* @var	bool	submit			Whether the user is sending the PM or not
		* @var	bool	preview			Whether the user is previewing the PM or not
		* @var	string	action			One of: post, reply, quote, forward, quotepost, edit, delete, smilies
		* @var	bool	delete			Whether the user is deleting the PM
		* @var	int		reply_to_all	Value of reply_to_all request variable.
		* @since 3.2.10-RC1
		* @since 3.3.1-RC1
		*/
		$vars = [
			'post',
			'msg_id',
			'to_user_id',
			'to_group_id',
			'submit',
			'preview',
			'action',
			'delete',
			'reply_to_all',
		];
		extract($phpbb_dispatcher->trigger_event('core.ucp_pm_compose_compose_pm_basic_info_query_after', compact($vars)));

		if (!$post)
		{
			// If editing it could be the recipient already read the message...
			if ($action == 'edit')
			{
				$sql = 'SELECT p.*, t.folder_id
					FROM ' . PRIVMSGS_TO_TABLE . ' t, ' . PRIVMSGS_TABLE . ' p
					WHERE t.user_id = ' . $user->data['user_id'] . "
						AND t.msg_id = $msg_id
						AND t.msg_id = p.msg_id";
				$result = $db->sql_query($sql);
				$post = $db->sql_fetchrow($result);
				$db->sql_freeresult($result);

				if ($post)
				{
					trigger_error('NO_EDIT_READ_MESSAGE');
				}
			}

			trigger_error('NO_MESSAGE');
		}

		if ($action == 'quotepost')
		{
			if (($post['forum_id'] && !$auth->acl_get('f_read', $post['forum_id'])) || (!$post['forum_id'] && !$auth->acl_getf_global('f_read')))
			{
				send_status_line(403, 'Forbidden');
				trigger_error('NOT_AUTHORISED');
			}

			/**
			* Get the result of querying for the post to be quoted in the pm message
			*
			* @event core.ucp_pm_compose_quotepost_query_after
			* @var	string	sql					The original SQL used in the query
			* @var	array	post				Associative array with the data of the quoted post
			* @var	array	msg_id				The post_id that was searched to get the message for quoting
			* @var	int		to_user_id			Users the message is sent to
			* @var	int		to_group_id			Groups the message is sent to
			* @var	bool	submit				Whether the user is sending the PM or not
			* @var	bool	preview				Whether the user is previewing the PM or not
			* @var	string	action				One of: post, reply, quote, forward, quotepost, edit, delete, smilies
			* @var	bool	delete				If deleting message
			* @var	int		reply_to_all		Value of reply_to_all request variable.
			* @since 3.1.0-RC5
			* @changed 3.2.0-a1 Removed undefined variables
			*/
			$vars = array(
				'sql',
				'post',
				'msg_id',
				'to_user_id',
				'to_group_id',
				'submit',
				'preview',
				'action',
				'delete',
				'reply_to_all',
			);
			extract($phpbb_dispatcher->trigger_event('core.ucp_pm_compose_quotepost_query_after', compact($vars)));

			// Passworded forum?
			if ($post['forum_id'])
			{
				$sql = 'SELECT forum_id, forum_name, forum_password
					FROM ' . FORUMS_TABLE . '
					WHERE forum_id = ' . (int) $post['forum_id'];
				$result = $db->sql_query($sql);
				$forum_data = $db->sql_fetchrow($result);
				$db->sql_freeresult($result);

				if (!empty($forum_data['forum_password']))
				{
					login_forum_box($forum_data);
				}
			}
		}

		$msg_id			= (int) $post['msg_id'];
		$folder_id		= (isset($post['folder_id'])) ? $post['folder_id'] : 0;
		$message_text	= (isset($post['message_text'])) ? $post['message_text'] : '';

		if ((!$post['author_id'] || ($post['author_id'] == ANONYMOUS && $action != 'delete')) && $msg_id)
		{
			trigger_error('NO_AUTHOR');
		}

		if ($action == 'quotepost')
		{
			// Decode text for message display
			decode_message($message_text, $post['bbcode_uid']);
		}

		if ($action != 'delete')
		{
			$enable_urls = $post['enable_magic_url'];
			$enable_sig = (isset($post['enable_sig'])) ? $post['enable_sig'] : 0;

			$message_attachment = (isset($post['message_attachment'])) ? $post['message_attachment'] : 0;
			$message_subject = $post['message_subject'];
			$message_time = $post['message_time'];
			$bbcode_uid = $post['bbcode_uid'];

			$quote_username = (isset($post['quote_username'])) ? $post['quote_username'] : '';
			$icon_id = (isset($post['icon_id'])) ? $post['icon_id'] : 0;

			if (($action == 'reply' || $action == 'quote' || $action == 'quotepost') && !count($address_list) && !$refresh && !$submit && !$preview)
			{
				// Add the original author as the recipient if quoting a post or only replying and not having checked "reply to all"
				if ($action == 'quotepost' || !$reply_to_all)
				{
					$address_list = array('u' => array($post['author_id'] => 'to'));
				}
				else
				{
					// We try to include every previously listed member from the TO Header - Reply to all
					$address_list = rebuild_header(array('to' => $post['to_address']));

					// Add the author (if he is already listed then this is no shame (it will be overwritten))
					$address_list['u'][$post['author_id']] = 'to';

					// Now, make sure the user itself is not listed. ;)
					if (isset($address_list['u'][$user->data['user_id']]))
					{
						unset($address_list['u'][$user->data['user_id']]);
					}
				}
			}
			else if ($action == 'edit' && !count($address_list) && !$refresh && !$submit && !$preview)
			{
				// Rebuild TO and BCC Header
				$address_list = rebuild_header(array('to' => $post['to_address'], 'bcc' => $post['bcc_address']));
			}

			if ($action == 'quotepost')
			{
				$check_value = 0;
			}
			else
			{
				$check_value = (($post['enable_bbcode']+1) << 8) + (($post['enable_smilies']+1) << 4) + (($enable_urls+1) << 2) + (($post['enable_sig']+1) << 1);
			}
		}
	}
	else
	{
		$message_attachment = 0;
		$message_text = $message_subject = '';

		/**
		* Predefine message text and subject
		*
		* @event core.ucp_pm_compose_predefined_message
		* @var	string	message_text	Message text
		* @var	string	message_subject	Messate subject
		* @since 3.1.11-RC1
		*/
		$vars = array('message_text', 'message_subject');
		extract($phpbb_dispatcher->trigger_event('core.ucp_pm_compose_predefined_message', compact($vars)));

		if ($to_user_id && $to_user_id != ANONYMOUS && $action == 'post')
		{
			$address_list['u'][$to_user_id] = 'to';
		}
		else if ($to_group_id && $action == 'post')
		{
			$address_list['g'][$to_group_id] = 'to';
		}
		$check_value = 0;
	}

	if (($to_group_id || isset($address_list['g'])) && (!$config['allow_mass_pm'] || !$auth->acl_get('u_masspm_group')))
	{
		send_status_line(403, 'Forbidden');
		trigger_error('NO_AUTH_GROUP_MESSAGE');
	}

	if ($action == 'edit' && !$refresh && !$preview && !$submit)
	{
		if (!($message_time > time() - ($config['pm_edit_time'] * 60) || !$config['pm_edit_time']))
		{
			trigger_error('CANNOT_EDIT_MESSAGE_TIME');
		}
	}

	if ($action == 'post')
	{
		$template->assign_var('S_NEW_MESSAGE', true);
	}

	if (!isset($icon_id))
	{
		$icon_id = 0;
	}

	/* @var $plupload \phpbb\plupload\plupload */
	$plupload = $phpbb_container->get('plupload');
	$message_parser = new parse_message();
	$message_parser->set_plupload($plupload);

	$message_parser->message = ($action == 'reply') ? '' : $message_text;
	unset($message_text);

	$s_action = append_sid("{$phpbb_root_path}ucp.$phpEx", "i=$id&amp;mode=$mode&amp;action=$action");
	$s_action .= (($folder_id) ? "&amp;f=$folder_id" : '') . (($msg_id) ? "&amp;p=$msg_id" : '');

	// Delete triggered ?
	if ($action == 'delete')
	{
		// Folder id has been determined by the SQL Statement
		// $folder_id = $request->variable('f', PRIVMSGS_NO_BOX);

		// Do we need to confirm ?
		if (confirm_box(true))
		{
			delete_pm($user->data['user_id'], $msg_id, $folder_id);

			// jump to next message in "history"? nope, not for the moment. But able to be included later.
			$meta_info = append_sid("{$phpbb_root_path}ucp.$phpEx", "i=pm&amp;folder=$folder_id");
			$message = $user->lang['MESSAGE_DELETED'];

			meta_refresh(3, $meta_info);
			$message .= '<br /><br />' . sprintf($user->lang['RETURN_FOLDER'], '<a href="' . $meta_info . '">', '</a>');
			trigger_error($message);
		}
		else
		{
			$s_hidden_fields = array(
				'p'			=> $msg_id,
				'f'			=> $folder_id,
				'action'	=> 'delete'
			);

			// "{$phpbb_root_path}ucp.$phpEx?i=pm&amp;mode=compose"
			confirm_box(false, 'DELETE_MESSAGE', build_hidden_fields($s_hidden_fields));
		}

		redirect(append_sid("{$phpbb_root_path}ucp.$phpEx", 'i=pm&amp;mode=view&amp;action=view_message&amp;p=' . $msg_id));
	}

	// Get maximum number of allowed recipients
	$max_recipients = phpbb_get_max_setting_from_group($db, $user->data['user_id'], 'max_recipients');

	// If it is 0, there is no limit set and we use the maximum value within the config.
	$max_recipients = (!$max_recipients) ? $config['pm_max_recipients'] : $max_recipients;

	// If this is a quote/reply "to all"... we may increase the max_recpients to the number of original recipients
	if (($action == 'reply' || $action == 'quote') && $max_recipients && $reply_to_all)
	{
		// We try to include every previously listed member from the TO Header
		$list = rebuild_header(array('to' => $post['to_address']));

		// Can be an empty array too ;)
		$list = (!empty($list['u'])) ? $list['u'] : array();
		$list[$post['author_id']] = 'to';

		if (isset($list[$user->data['user_id']]))
		{
			unset($list[$user->data['user_id']]);
		}

		$max_recipients = ($max_recipients < count($list)) ? count($list) : $max_recipients;

		unset($list);
	}

	// Handle User/Group adding/removing
	handle_message_list_actions($address_list, $error, $remove_u, $remove_g, $add_to, $add_bcc);

	// Check mass pm to group permission
	if ((!$config['allow_mass_pm'] || !$auth->acl_get('u_masspm_group')) && !empty($address_list['g']))
	{
		$address_list = array();
		$error[] = $user->lang['NO_AUTH_GROUP_MESSAGE'];
	}

	// Check mass pm to users permission
	if ((!$config['allow_mass_pm'] || !$auth->acl_get('u_masspm')) && num_recipients($address_list) > 1)
	{
		$address_list = get_recipients($address_list, 1);
		$error[] = $user->lang('TOO_MANY_RECIPIENTS', 1);
	}

	// Check for too many recipients
	if (!empty($address_list['u']) && $max_recipients && count($address_list['u']) > $max_recipients)
	{
		$address_list = get_recipients($address_list, $max_recipients);
		$error[] = $user->lang('TOO_MANY_RECIPIENTS', $max_recipients);
	}

	// Always check if the submitted attachment data is valid and belongs to the user.
	// Further down (especially in submit_post()) we do not check this again.
	$message_parser->get_submitted_attachment_data();

	if ($message_attachment && !$submit && !$refresh && !$preview && $action == 'edit')
	{
		// Do not change to SELECT *
		$sql = 'SELECT attach_id, is_orphan, attach_comment, real_filename, filesize
			FROM ' . ATTACHMENTS_TABLE . "
			WHERE post_msg_id = $msg_id
				AND in_message = 1
				AND is_orphan = 0
			ORDER BY filetime DESC";
		$result = $db->sql_query($sql);
		$message_parser->attachment_data = array_merge($message_parser->attachment_data, $db->sql_fetchrowset($result));
		$db->sql_freeresult($result);
	}

	if (!in_array($action, array('quote', 'edit', 'delete', 'forward')))
	{
		$enable_sig		= ($config['allow_sig'] && $config['allow_sig_pm'] && $auth->acl_get('u_sig') && $user->optionget('attachsig'));
		$enable_smilies	= ($config['allow_smilies'] && $auth->acl_get('u_pm_smilies') && $user->optionget('smilies'));
		$enable_bbcode	= ($config['allow_bbcode'] && $auth->acl_get('u_pm_bbcode') && $user->optionget('bbcode'));
		$enable_urls	= true;
	}

	$drafts = false;

	// User own some drafts?
	if ($auth->acl_get('u_savedrafts') && $action != 'delete')
	{
		$sql = 'SELECT draft_id
			FROM ' . DRAFTS_TABLE . '
			WHERE forum_id = 0
				AND topic_id = 0
				AND user_id = ' . $user->data['user_id'] .
				(($draft_id) ? " AND draft_id <> $draft_id" : '');
		$result = $db->sql_query_limit($sql, 1);
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		if ($row)
		{
			$drafts = true;
		}
	}

	if ($action == 'edit')
	{
		$message_parser->bbcode_uid = $bbcode_uid;
	}

	$bbcode_status	= ($config['allow_bbcode'] && $config['auth_bbcode_pm'] && $auth->acl_get('u_pm_bbcode')) ? true : false;
	$smilies_status	= ($config['allow_smilies'] && $config['auth_smilies_pm'] && $auth->acl_get('u_pm_smilies')) ? true : false;
	$img_status		= ($config['auth_img_pm'] && $auth->acl_get('u_pm_img')) ? true : false;
	$flash_status	= ($config['auth_flash_pm'] && $auth->acl_get('u_pm_flash')) ? true : false;
	$url_status		= ($config['allow_post_links']) ? true : false;

	/**
	 * Event to override private message BBCode status indications
	 *
	 * @event core.ucp_pm_compose_modify_bbcode_status
	 *
	 * @var bool	bbcode_status	BBCode status
	 * @var bool	smilies_status	Smilies status
	 * @var bool	img_status		Image BBCode status
	 * @var bool	flash_status	Flash BBCode status
	 * @var bool	url_status		URL BBCode status
	 * @since 3.3.3-RC1
	 */
	$vars = [
		'bbcode_status',
		'smilies_status',
		'img_status',
		'flash_status',
		'url_status',
	];
	extract($phpbb_dispatcher->trigger_event('core.ucp_pm_compose_modify_bbcode_status', compact($vars)));

	// Save Draft
	if ($save && $auth->acl_get('u_savedrafts'))
	{
		$subject = $request->variable('subject', '', true);
		$subject = (!$subject && $action != 'post') ? $user->lang['NEW_MESSAGE'] : $subject;
		$message = $request->variable('message', '', true);

		/**
		 * Replace Emojis and other 4bit UTF-8 chars not allowed by MySQL to UCR/NCR.
		 * Using their Numeric Character Reference's Hexadecimal notation.
		 */
		$subject = utf8_encode_ucr($subject);

		if ($subject && $message)
		{
			if (confirm_box(true))
			{
				$message_parser->message = $message;
				$message_parser->parse($bbcode_status, $url_status, $smilies_status, $img_status, $flash_status, true, $url_status);

				$sql = 'INSERT INTO ' . DRAFTS_TABLE . ' ' . $db->sql_build_array('INSERT', array(
					'user_id'		=> $user->data['user_id'],
					'topic_id'		=> 0,
					'forum_id'		=> 0,
					'save_time'		=> $current_time,
					'draft_subject'	=> $subject,
					'draft_message'	=> $message_parser->message,
					)
				);
				$db->sql_query($sql);

				/** @var \phpbb\attachment\manager $attachment_manager */
				$attachment_manager = $phpbb_container->get('attachment.manager');
				$attachment_manager->delete('attach', array_column($message_parser->attachment_data, 'attach_id'));

				$redirect_url = append_sid("{$phpbb_root_path}ucp.$phpEx", "i=pm&amp;mode=$mode");

				meta_refresh(3, $redirect_url);
				$message = $user->lang['DRAFT_SAVED'] . '<br /><br />' . sprintf($user->lang['RETURN_UCP'], '<a href="' . $redirect_url . '">', '</a>');

				trigger_error($message);
			}
			else
			{
				$s_hidden_fields = build_hidden_fields(array(
					'mode'		=> $mode,
					'action'	=> $action,
					'save'		=> true,
					'subject'	=> $subject,
					'message'	=> $message,
					'u'			=> $to_user_id,
					'g'			=> $to_group_id,
					'p'			=> $msg_id,
					'attachment_data' => $message_parser->attachment_data,
				));
				$s_hidden_fields .= build_address_field($address_list);

				confirm_box(false, 'SAVE_DRAFT', $s_hidden_fields);
			}
		}
		else
		{
			if (utf8_clean_string($subject) === '')
			{
				$error[] = $user->lang['EMPTY_MESSAGE_SUBJECT'];
			}

			if (utf8_clean_string($message) === '')
			{
				$error[] = $user->lang['TOO_FEW_CHARS'];
			}
		}

		unset($subject, $message);
	}

	// Load Draft
	if ($draft_id && $auth->acl_get('u_savedrafts'))
	{
		$sql = 'SELECT draft_subject, draft_message
			FROM ' . DRAFTS_TABLE . "
			WHERE draft_id = $draft_id
				AND topic_id = 0
				AND forum_id = 0
				AND user_id = " . $user->data['user_id'];
		$result = $db->sql_query_limit($sql, 1);

		if ($row = $db->sql_fetchrow($result))
		{
			$message_parser->message = $row['draft_message'];
			$message_subject = $row['draft_subject'];

			$template->assign_var('S_DRAFT_LOADED', true);
		}
		else
		{
			$draft_id = 0;
		}
		$db->sql_freeresult($result);
	}

	// Load Drafts
	if ($load && $drafts)
	{
		load_drafts(0, 0, $id, $action, $msg_id);
	}

	if ($submit || $preview || $refresh)
	{
		if (($submit || $preview) && !check_form_key('ucp_pm_compose'))
		{
			$error[] = $user->lang['FORM_INVALID'];
		}
		$subject = $request->variable('subject', '', true);
		$message_parser->message = $request->variable('message', '', true);

		$icon_id			= $request->variable('icon', 0);

		$enable_bbcode 		= (!$bbcode_status || isset($_POST['disable_bbcode'])) ? false : true;
		$enable_smilies		= (!$smilies_status || isset($_POST['disable_smilies'])) ? false : true;
		$enable_urls 		= (isset($_POST['disable_magic_url'])) ? 0 : 1;
		$enable_sig			= (!$config['allow_sig'] ||!$config['allow_sig_pm']) ? false : ((isset($_POST['attach_sig'])) ? true : false);

		/**
		* Modify private message
		*
		* @event core.ucp_pm_compose_modify_parse_before
		* @var	bool	enable_bbcode		Whether or not bbcode is enabled
		* @var	bool	enable_smilies		Whether or not smilies are enabled
		* @var	bool	enable_urls			Whether or not urls are enabled
		* @var	bool	enable_sig			Whether or not signature is enabled
		* @var	string	subject				PM subject text
		* @var	object	message_parser		The message parser object
		* @var	bool	submit				Whether or not the form has been sumitted
		* @var	bool	preview				Whether or not the signature is being previewed
		* @var	array	error				Any error strings
		* @since 3.1.10-RC1
		*/
		$vars = array(
			'enable_bbcode',
			'enable_smilies',
			'enable_urls',
			'enable_sig',
			'subject',
			'message_parser',
			'submit',
			'preview',
			'error',
		);
		extract($phpbb_dispatcher->trigger_event('core.ucp_pm_compose_modify_parse_before', compact($vars)));

		// Parse Attachments - before checksum is calculated
		if ($message_parser->check_attachment_form_token($language, $request, 'ucp_pm_compose'))
		{
			$message_parser->parse_attachments('fileupload', $action, 0, $submit, $preview, $refresh, true);
		}

		if (count($message_parser->warn_msg) && !($remove_u || $remove_g || $add_to || $add_bcc))
		{
			$error[] = implode('<br />', $message_parser->warn_msg);
			$message_parser->warn_msg = array();
		}

		// Parse message
		$message_parser->parse($enable_bbcode, ($config['allow_post_links']) ? $enable_urls : false, $enable_smilies, $img_status, $flash_status, true, $config['allow_post_links']);

		// On a refresh we do not care about message parsing errors
		if (count($message_parser->warn_msg) && !$refresh)
		{
			$error[] = implode('<br />', $message_parser->warn_msg);
		}

		if ($action != 'edit' && !$preview && !$refresh && $config['flood_interval'] && !$auth->acl_get('u_ignoreflood'))
		{
			// Flood check
			$last_post_time = $user->data['user_lastpost_time'];

			if ($last_post_time)
			{
				if ($last_post_time && ($current_time - $last_post_time) < intval($config['flood_interval']))
				{
					$error[] = $user->lang['FLOOD_ERROR'];
				}
			}
		}

		// Subject defined
		if ($submit)
		{
			if (utf8_clean_string($subject) === '')
			{
				$error[] = $user->lang['EMPTY_MESSAGE_SUBJECT'];
			}

			if (!count($address_list))
			{
				$error[] = $user->lang['NO_RECIPIENT'];
			}
		}

		/**
		* Modify private message
		*
		* @event core.ucp_pm_compose_modify_parse_after
		* @var	bool	enable_bbcode		Whether or not bbcode is enabled
		* @var	bool	enable_smilies		Whether or not smilies are enabled
		* @var	bool	enable_urls			Whether or not urls are enabled
		* @var	bool	enable_sig			Whether or not signature is enabled
		* @var	string	subject				PM subject text
		* @var	object	message_parser		The message parser object
		* @var	bool	submit				Whether or not the form has been sumitted
		* @var	bool	preview				Whether or not the signature is being previewed
		* @var	array	error				Any error strings
		* @since 3.2.10-RC1
		* @since 3.3.1-RC1
		*/
		$vars = [
			'enable_bbcode',
			'enable_smilies',
			'enable_urls',
			'enable_sig',
			'subject',
			'message_parser',
			'submit',
			'preview',
			'error',
		];
		extract($phpbb_dispatcher->trigger_event('core.ucp_pm_compose_modify_parse_after', compact($vars)));

		// Store message, sync counters
		if (!count($error) && $submit)
		{
			$pm_data = array(
				'msg_id'				=> (int) $msg_id,
				'from_user_id'			=> $user->data['user_id'],
				'from_user_ip'			=> $user->ip,
				'from_username'			=> $user->data['username'],
				'reply_from_root_level'	=> (isset($post['root_level'])) ? (int) $post['root_level'] : 0,
				'reply_from_msg_id'		=> (int) $msg_id,
				'icon_id'				=> (int) $icon_id,
				'enable_sig'			=> (bool) $enable_sig,
				'enable_bbcode'			=> (bool) $enable_bbcode,
				'enable_smilies'		=> (bool) $enable_smilies,
				'enable_urls'			=> (bool) $enable_urls,
				'bbcode_bitfield'		=> $message_parser->bbcode_bitfield,
				'bbcode_uid'			=> $message_parser->bbcode_uid,
				'message'				=> $message_parser->message,
				'attachment_data'		=> $message_parser->attachment_data,
				'filename_data'			=> $message_parser->filename_data,
				'address_list'			=> $address_list
			);

			/**
			 * Replace Emojis and other 4bit UTF-8 chars not allowed by MySQL to UCR/NCR.
			 * Using their Numeric Character Reference's Hexadecimal notation.
			 */
			$subject = utf8_encode_ucr($subject);

			// ((!$message_subject) ? $subject : $message_subject)
			$msg_id = submit_pm($action, $subject, $pm_data);

			$return_message_url = append_sid("{$phpbb_root_path}ucp.$phpEx", 'i=pm&amp;mode=view&amp;p=' . $msg_id);
			$inbox_folder_url = append_sid("{$phpbb_root_path}ucp.$phpEx", 'i=pm&amp;folder=inbox');
			$outbox_folder_url = append_sid("{$phpbb_root_path}ucp.$phpEx", 'i=pm&amp;folder=outbox');

			$folder_url = '';
			if (($folder_id > 0) && isset($user_folders[$folder_id]))
			{
				$folder_url = append_sid("{$phpbb_root_path}ucp.$phpEx", 'i=pm&amp;folder=' . $folder_id);
			}

			$return_box_url = ($action === 'post' || $action === 'edit') ? $outbox_folder_url : $inbox_folder_url;
			$return_box_lang = ($action === 'post' || $action === 'edit') ? 'PM_OUTBOX' : 'PM_INBOX';

			$save_message = ($action === 'edit') ? $user->lang['MESSAGE_EDITED'] : $user->lang['MESSAGE_STORED'];
			$message = $save_message . '<br /><br />' . $user->lang('VIEW_PRIVATE_MESSAGE', '<a href="' . $return_message_url . '">', '</a>');

			$last_click_type = 'CLICK_RETURN_FOLDER';
			if ($folder_url)
			{
				$message .= '<br /><br />' . sprintf($user->lang['CLICK_RETURN_FOLDER'], '<a href="' . $folder_url . '">', '</a>', $user_folders[$folder_id]['folder_name']);
				$last_click_type = 'CLICK_GOTO_FOLDER';
			}
			$message .= '<br /><br />' . sprintf($user->lang[$last_click_type], '<a href="' . $return_box_url . '">', '</a>', $user->lang[$return_box_lang]);

			meta_refresh(3, $return_message_url);
			trigger_error($message);
		}

		$message_subject = $subject;
	}

	// Preview
	if (!count($error) && $preview)
	{
		$preview_message = $message_parser->format_display($enable_bbcode, $enable_urls, $enable_smilies, false);

		$preview_signature = $user->data['user_sig'];
		$preview_signature_uid = $user->data['user_sig_bbcode_uid'];
		$preview_signature_bitfield = $user->data['user_sig_bbcode_bitfield'];

		// Signature
		if ($enable_sig && $config['allow_sig'] && $preview_signature)
		{
			$bbcode_flags = ($enable_bbcode ? OPTION_FLAG_BBCODE : 0) + ($enable_smilies ? OPTION_FLAG_SMILIES : 0) + ($enable_urls ? OPTION_FLAG_LINKS : 0);
			$preview_signature = generate_text_for_display($preview_signature, $preview_signature_uid, $preview_signature_bitfield, $bbcode_flags);
		}
		else
		{
			$preview_signature = '';
		}

		// Attachment Preview
		if (count($message_parser->attachment_data))
		{
			$template->assign_var('S_HAS_ATTACHMENTS', true);

			$update_count = array();
			$attachment_data = $message_parser->attachment_data;

			parse_attachments(false, $preview_message, $attachment_data, $update_count, true);

			foreach ($attachment_data as $i => $attachment)
			{
				$template->assign_block_vars('attachment', array(
					'DISPLAY_ATTACHMENT'	=> $attachment)
				);
			}
			unset($attachment_data);
		}

		$preview_subject = censor_text($subject);

		if (!count($error))
		{
			$template->assign_vars(array(
				'PREVIEW_SUBJECT'		=> $preview_subject,
				'PREVIEW_MESSAGE'		=> $preview_message,
				'PREVIEW_SIGNATURE'		=> $preview_signature,

				'S_DISPLAY_PREVIEW'		=> true)
			);
		}
		unset($message_text);
	}

	// Decode text for message display
	$bbcode_uid = (($action == 'quote' || $action == 'forward') && !$preview && !$refresh && (!count($error) || (count($error) && !$submit))) ? $bbcode_uid : $message_parser->bbcode_uid;

	$message_parser->decode_message($bbcode_uid);

	if (($action == 'quote' || $action == 'quotepost') && !$preview && !$refresh && !$submit)
	{
		if ($action == 'quotepost')
		{
			$post_id = $request->variable('p', 0);
			if ($config['allow_post_links'])
			{
				$message_link = generate_board_url() . "/viewtopic.$phpEx?p={$post_id}#p{$post_id}";
				$message_link_subject = "{$user->lang['SUBJECT']}{$user->lang['COLON']} {$message_subject}";
				if ($bbcode_status)
				{
					$message_link = "[url=" . $message_link . "]" . $message_link_subject . "[/url]\n\n";
				}
				else
				{
					$message_link = $message_link . " - " . $message_link_subject . "\n\n";
				}
			}
			else
			{
				$message_link = $user->lang['SUBJECT'] . $user->lang['COLON'] . ' ' . $message_subject . " (" . generate_board_url() . "/viewtopic.$phpEx?p={$post_id}#p{$post_id})\n\n";
			}
		}
		else
		{
			$message_link = '';
		}
		$quote_attributes = array(
			'author'  => $quote_username,
			'time'    => $post['message_time'],
			'user_id' => $post['author_id'],
		);
		if ($action === 'quotepost')
		{
			$quote_attributes['post_id'] = $post['msg_id'];
		}
		if ($action === 'quote')
		{
			$quote_attributes['msg_id'] = $post['msg_id'];
		}
		/** @var \phpbb\language\language $language */
		$language = $phpbb_container->get('language');
		/** @var \phpbb\textformatter\utils_interface $text_formatter_utils */
		$text_formatter_utils = $phpbb_container->get('text_formatter.utils');
		phpbb_format_quote($language, $message_parser, $text_formatter_utils, $bbcode_status, $quote_attributes, $message_link);
	}

	if (($action == 'reply' || $action == 'quote' || $action == 'quotepost') && !$preview && !$refresh)
	{
		$message_subject = ((!preg_match('/^Re:/', $message_subject)) ? 'Re: ' : '') . censor_text($message_subject);

		/**
		* This event allows you to modify the PM subject of the PM being quoted
		*
		* @event core.pm_modify_message_subject
		* @var	string		message_subject		String with the PM subject already censored.
		* @since 3.2.8-RC1
		*/
		$vars = array('message_subject');
		extract($phpbb_dispatcher->trigger_event('core.pm_modify_message_subject', compact($vars)));
	}

	if ($action == 'forward' && !$preview && !$refresh && !$submit)
	{
		$fwd_to_field = write_pm_addresses(array('to' => $post['to_address']), 0, true);

		if ($config['allow_post_links'])
		{
			$quote_username_text = '[url=' . generate_board_url() . "/memberlist.$phpEx?mode=viewprofile&amp;u={$post['author_id']}]{$quote_username}[/url]";
		}
		else
		{
			$quote_username_text = $quote_username . ' (' . generate_board_url() . "/memberlist.$phpEx?mode=viewprofile&amp;u={$post['author_id']})";
		}

		$forward_text = array();
		$forward_text[] = $user->lang['FWD_ORIGINAL_MESSAGE'];
		$forward_text[] = sprintf($user->lang['FWD_SUBJECT'], censor_text($message_subject));
		$forward_text[] = sprintf($user->lang['FWD_DATE'], $user->format_date($message_time, false, true));
		$forward_text[] = sprintf($user->lang['FWD_FROM'], $quote_username_text);
		$forward_text[] = sprintf($user->lang['FWD_TO'], implode($user->lang['COMMA_SEPARATOR'], $fwd_to_field['to']));

		$quote_text = $phpbb_container->get('text_formatter.utils')->generate_quote(
			censor_text($message_parser->message),
			array('author' => $quote_username)
		);
		$message_parser->message = implode("\n", $forward_text) . "\n\n" . $quote_text;
		$message_subject = ((!preg_match('/^Fwd:/', $message_subject)) ? 'Fwd: ' : '') . censor_text($message_subject);
	}

	$attachment_data = $message_parser->attachment_data;
	$filename_data = $message_parser->filename_data;
	$message_text = $message_parser->message;

	// MAIN PM PAGE BEGINS HERE

	// Generate smiley listing
	generate_smilies('inline', 0);

	// Generate PM Icons
	$s_pm_icons = false;
	if ($config['enable_pm_icons'])
	{
		$s_pm_icons = posting_gen_topic_icons($action, $icon_id);
	}

	// Generate inline attachment select box
	posting_gen_inline_attachments($attachment_data);

	// Build address list for display
	// array('u' => array($author_id => 'to'));
	if (count($address_list))
	{
		// Get Usernames and Group Names
		$result = array();
		if (!empty($address_list['u']))
		{
			$sql = 'SELECT user_id as id, username as name, user_colour as colour
				FROM ' . USERS_TABLE . '
				WHERE ' . $db->sql_in_set('user_id', array_map('intval', array_keys($address_list['u']))) . '
				ORDER BY username_clean ASC';
			$result['u'] = $db->sql_query($sql);
		}

		if (!empty($address_list['g']))
		{
			$sql = 'SELECT g.group_id AS id, g.group_name AS name, g.group_colour AS colour, g.group_type
				FROM ' . GROUPS_TABLE . ' g';

			if (!$auth->acl_gets('a_group', 'a_groupadd', 'a_groupdel'))
			{
				$sql .= ' LEFT JOIN ' . USER_GROUP_TABLE . ' ug
					ON (
						g.group_id = ug.group_id
						AND ug.user_id = ' . $user->data['user_id'] . '
						AND ug.user_pending = 0
					)
					WHERE (g.group_type <> ' . GROUP_HIDDEN . ' OR ug.user_id = ' . $user->data['user_id'] . ')';
			}

			$sql .= ($auth->acl_gets('a_group', 'a_groupadd', 'a_groupdel')) ? ' WHERE ' : ' AND ';

			$sql .= 'g.group_receive_pm = 1
				AND ' . $db->sql_in_set('g.group_id', array_map('intval', array_keys($address_list['g']))) . '
				ORDER BY g.group_name ASC';

			$result['g'] = $db->sql_query($sql);
		}

		$u = $g = array();
		$_types = array('u', 'g');
		foreach ($_types as $type)
		{
			if (isset($result[$type]) && $result[$type])
			{
				while ($row = $db->sql_fetchrow($result[$type]))
				{
					if ($type == 'g')
					{
						$row['name'] = $group_helper->get_name($row['name']);
					}

					${$type}[$row['id']] = array('name' => $row['name'], 'colour' => $row['colour']);
				}
				$db->sql_freeresult($result[$type]);
			}
		}

		// Now Build the address list
		foreach ($address_list as $type => $adr_ary)
		{
			foreach ($adr_ary as $id => $field)
			{
				if (!isset(${$type}[$id]))
				{
					unset($address_list[$type][$id]);
					continue;
				}

				$field = ($field == 'to') ? 'to' : 'bcc';
				$type = ($type == 'u') ? 'u' : 'g';
				$id = (int) $id;

				$tpl_ary = array(
					'IS_GROUP'	=> ($type == 'g') ? true : false,
					'IS_USER'	=> ($type == 'u') ? true : false,
					'UG_ID'		=> $id,
					'NAME'		=> ${$type}[$id]['name'],
					'COLOUR'	=> (${$type}[$id]['colour']) ? '#' . ${$type}[$id]['colour'] : '',
					'TYPE'		=> $type,
				);

				if ($type == 'u')
				{
					$tpl_ary = array_merge($tpl_ary, array(
						'U_VIEW'		=> get_username_string('profile', $id, ${$type}[$id]['name'], ${$type}[$id]['colour']),
						'NAME_FULL'		=> get_username_string('full', $id, ${$type}[$id]['name'], ${$type}[$id]['colour']),
					));
				}
				else
				{
					$tpl_ary = array_merge($tpl_ary, array(
						'U_VIEW'		=> append_sid("{$phpbb_root_path}memberlist.$phpEx", 'mode=group&amp;g=' . $id),
					));
				}

				$template->assign_block_vars($field . '_recipient', $tpl_ary);
			}
		}
	}

	// Build hidden address list
	$s_hidden_address_field = build_address_field($address_list);

	$bbcode_checked		= (isset($enable_bbcode)) ? !$enable_bbcode : (($config['allow_bbcode'] && $auth->acl_get('u_pm_bbcode')) ? !$user->optionget('bbcode') : 1);
	$smilies_checked	= (isset($enable_smilies)) ? !$enable_smilies : (($config['allow_smilies'] && $auth->acl_get('u_pm_smilies')) ? !$user->optionget('smilies') : 1);
	$urls_checked		= (isset($enable_urls)) ? !$enable_urls : 0;
	$sig_checked		= $enable_sig;

	switch ($action)
	{
		case 'post':
			$page_title = $user->lang['POST_NEW_PM'];
		break;

		case 'quote':
			$page_title = $user->lang['POST_QUOTE_PM'];
		break;

		case 'quotepost':
			$page_title = $user->lang['POST_PM_POST'];
		break;

		case 'reply':
			$page_title = $user->lang['POST_REPLY_PM'];
		break;

		case 'edit':
			$page_title = $user->lang['POST_EDIT_PM'];
		break;

		case 'forward':
			$page_title = $user->lang['POST_FORWARD_PM'];
		break;

		default:
			trigger_error('NO_ACTION_MODE', E_USER_ERROR);
		break;
	}

	$s_hidden_fields = (isset($check_value)) ? '<input type="hidden" name="status_switch" value="' . $check_value . '" />' : '';
	$s_hidden_fields .= ($draft_id || isset($_REQUEST['draft_loaded'])) ? '<input type="hidden" name="draft_loaded" value="' . ((isset($_REQUEST['draft_loaded'])) ? $request->variable('draft_loaded', 0) : $draft_id) . '" />' : '';

	$form_enctype = (@ini_get('file_uploads') == '0' || strtolower(@ini_get('file_uploads')) == 'off' || !$config['allow_pm_attach'] || !$auth->acl_get('u_pm_attach')) ? '' : ' enctype="multipart/form-data"';

	/** @var \phpbb\controller\helper $controller_helper */
	$controller_helper = $phpbb_container->get('controller.helper');

	// Start assigning vars for main posting page ...
	$template_ary = array(
		'L_POST_A'					=> $page_title,
		'L_ICON'					=> $user->lang['PM_ICON'],
		'L_MESSAGE_BODY_EXPLAIN'	=> $user->lang('MESSAGE_BODY_EXPLAIN', (int) $config['max_post_chars']),

		'SUBJECT'				=> (isset($message_subject)) ? $message_subject : '',
		'MESSAGE'				=> $message_text,
		'BBCODE_STATUS'			=> $user->lang(($bbcode_status ? 'BBCODE_IS_ON' : 'BBCODE_IS_OFF'), '<a href="' . $controller_helper->route('phpbb_help_bbcode_controller') . '">', '</a>'),
		'IMG_STATUS'			=> ($img_status) ? $user->lang['IMAGES_ARE_ON'] : $user->lang['IMAGES_ARE_OFF'],
		'FLASH_STATUS'			=> ($flash_status) ? $user->lang['FLASH_IS_ON'] : $user->lang['FLASH_IS_OFF'],
		'SMILIES_STATUS'		=> ($smilies_status) ? $user->lang['SMILIES_ARE_ON'] : $user->lang['SMILIES_ARE_OFF'],
		'URL_STATUS'			=> ($url_status) ? $user->lang['URL_IS_ON'] : $user->lang['URL_IS_OFF'],
		'MAX_FONT_SIZE'			=> (int) $config['max_post_font_size'],
		'MINI_POST_IMG'			=> $user->img('icon_post_target', $user->lang['PM']),
		'ERROR'					=> (count($error)) ? implode('<br />', $error) : '',
		'MAX_RECIPIENTS'		=> ($config['allow_mass_pm'] && ($auth->acl_get('u_masspm') || $auth->acl_get('u_masspm_group'))) ? $max_recipients : 0,

		'S_COMPOSE_PM'			=> true,
		'S_EDIT_POST'			=> ($action == 'edit'),
		'S_SHOW_PM_ICONS'		=> $s_pm_icons,
		'S_BBCODE_ALLOWED'		=> ($bbcode_status) ? 1 : 0,
		'S_BBCODE_CHECKED'		=> ($bbcode_checked) ? ' checked="checked"' : '',
		'S_SMILIES_ALLOWED'		=> $smilies_status,
		'S_SMILIES_CHECKED'		=> ($smilies_checked) ? ' checked="checked"' : '',
		'S_SIG_ALLOWED'			=> ($config['allow_sig'] && $config['allow_sig_pm'] && $auth->acl_get('u_sig')),
		'S_SIGNATURE_CHECKED'	=> ($sig_checked) ? ' checked="checked"' : '',
		'S_LINKS_ALLOWED'		=> $url_status,
		'S_MAGIC_URL_CHECKED'	=> ($urls_checked) ? ' checked="checked"' : '',
		'S_SAVE_ALLOWED'		=> ($auth->acl_get('u_savedrafts') && $action != 'edit') ? true : false,
		'S_HAS_DRAFTS'			=> ($auth->acl_get('u_savedrafts') && $drafts),
		'S_FORM_ENCTYPE'		=> $form_enctype,
		'S_ATTACH_DATA'			=> json_encode($message_parser->attachment_data),

		'S_BBCODE_IMG'			=> $img_status,
		'S_BBCODE_FLASH'		=> $flash_status,
		'S_BBCODE_QUOTE'		=> true,
		'S_BBCODE_URL'			=> $url_status,

		'S_POST_ACTION'				=> $s_action,
		'S_HIDDEN_ADDRESS_FIELD'	=> $s_hidden_address_field,
		'S_HIDDEN_FIELDS'			=> $s_hidden_fields,

		'S_CLOSE_PROGRESS_WINDOW'	=> isset($_POST['add_file']),
		'U_PROGRESS_BAR'			=> append_sid("{$phpbb_root_path}posting.$phpEx", 'f=0&amp;mode=popup'),
		'UA_PROGRESS_BAR'			=> addslashes(append_sid("{$phpbb_root_path}posting.$phpEx", 'f=0&amp;mode=popup')),
	);

	/**
	* Modify the default template vars
	*
	* @event core.ucp_pm_compose_template
	* @var	array	template_ary	Template variables
	* @since 3.2.6-RC1
	*/
	$vars = array('template_ary');
	extract($phpbb_dispatcher->trigger_event('core.ucp_pm_compose_template', compact($vars)));

	$template->assign_vars($template_ary);

	// Build custom bbcodes array
	display_custom_bbcodes();

	// Show attachment box for adding attachments if true
	$allowed = ($auth->acl_get('u_pm_attach') && $config['allow_pm_attach'] && $form_enctype);

	if ($allowed)
	{
		$max_files = ($auth->acl_gets('a_', 'm_')) ? 0 : (int) $config['max_attachments_pm'];
		$plupload->configure($cache, $template, $s_action, false, $max_files);
	}

	// Attachment entry
	posting_gen_attachment_entry($attachment_data, $filename_data, $allowed);

	// Message History
	if ($action == 'reply' || $action == 'quote' || $action == 'forward')
	{
		if (message_history($msg_id, $user->data['user_id'], $post, array(), true))
		{
			$template->assign_var('S_DISPLAY_HISTORY', true);
		}
	}
}

/**
* For composing messages, handle list actions
*/
function handle_message_list_actions(&$address_list, &$error, $remove_u, $remove_g, $add_to, $add_bcc)
{
	global $auth, $db, $user;
	global $request, $phpbb_dispatcher;

	// Delete User [TO/BCC]
	if ($remove_u && $request->variable('remove_u', array(0 => '')))
	{
		$remove_user_id = array_keys($request->variable('remove_u', array(0 => '')));

		if (isset($remove_user_id[0]))
		{
			unset($address_list['u'][(int) $remove_user_id[0]]);
		}
	}

	// Delete Group [TO/BCC]
	if ($remove_g && $request->variable('remove_g', array(0 => '')))
	{
		$remove_group_id = array_keys($request->variable('remove_g', array(0 => '')));

		if (isset($remove_group_id[0]))
		{
			unset($address_list['g'][(int) $remove_group_id[0]]);
		}
	}

	// Add Selected Groups
	$group_list = $request->variable('group_list', array(0));

	// Build usernames to add
	$usernames = $request->variable('username', '', true);
	$usernames = (empty($usernames)) ? array() : array($usernames);

	$username_list = $request->variable('username_list', '', true);
	if ($username_list)
	{
		$usernames = array_merge($usernames, explode("\n", $username_list));
	}

	// If add to or add bcc not pressed, users could still have usernames listed they want to add...
	if (!$add_to && !$add_bcc && (count($group_list) || count($usernames)))
	{
		$add_to = true;

		global $refresh, $submit, $preview;

		$refresh = true;
		$submit = false;

		// Preview is only true if there was also a message entered
		if ($request->variable('message', ''))
		{
			$preview = true;
		}
	}

	// Add User/Group [TO]
	if ($add_to || $add_bcc)
	{
		$type = ($add_to) ? 'to' : 'bcc';

		if (count($group_list))
		{
			foreach ($group_list as $group_id)
			{
				$address_list['g'][$group_id] = $type;
			}
		}

		// User ID's to add...
		$user_id_ary = array();

		// Reveal the correct user_ids
		if (count($usernames))
		{
			$user_id_ary = array();
			user_get_id_name($user_id_ary, $usernames, array(USER_NORMAL, USER_FOUNDER, USER_INACTIVE));

			// If there are users not existing, we will at least print a notice...
			if (!count($user_id_ary))
			{
				$error[] = $user->lang['PM_NO_USERS'];
			}
		}

		// Add Friends if specified
		$friend_list = array_keys($request->variable('add_' . $type, array(0)));
		$user_id_ary = array_merge($user_id_ary, $friend_list);

		foreach ($user_id_ary as $user_id)
		{
			if ($user_id == ANONYMOUS)
			{
				continue;
			}

			$address_list['u'][$user_id] = $type;
		}
	}

	// Check for disallowed recipients
	if (!empty($address_list['u']))
	{
		$can_ignore_allow_pm = $auth->acl_gets('a_', 'm_') || $auth->acl_getf_global('m_');

		// Administrator deactivated users check and we need to check their
		//		PM status (do they want to receive PM's?)
		// 		Only check PM status if not a moderator or admin, since they
		//		are allowed to override this user setting
		$sql = 'SELECT user_id, user_allow_pm
			FROM ' . USERS_TABLE . '
			WHERE ' . $db->sql_in_set('user_id', array_keys($address_list['u'])) . '
				AND (
						(user_type = ' . USER_INACTIVE . '
						AND user_inactive_reason = ' . INACTIVE_MANUAL . ')
						' . ($can_ignore_allow_pm ? '' : ' OR user_allow_pm = 0') . '
					)';

		$result = $db->sql_query($sql);

		$removed_no_pm = $removed_no_permission = false;
		while ($row = $db->sql_fetchrow($result))
		{
			if (!$can_ignore_allow_pm && !$row['user_allow_pm'])
			{
				$removed_no_pm = true;
			}
			else
			{
				$removed_no_permission = true;
			}

			unset($address_list['u'][$row['user_id']]);
		}
		$db->sql_freeresult($result);

		// print a notice about users not being added who do not want to receive pms
		if ($removed_no_pm)
		{
			$error[] = $user->lang['PM_USERS_REMOVED_NO_PM'];
		}

		// print a notice about users not being added who do not have permission to receive PMs
		if ($removed_no_permission)
		{
			$error[] = $user->lang['PM_USERS_REMOVED_NO_PERMISSION'];
		}

		if (!count(array_keys($address_list['u'])))
		{
			return;
		}

		// Check if users have permission to read PMs
		$can_read = $auth->acl_get_list(array_keys($address_list['u']), 'u_readpm');
		$can_read = (empty($can_read) || !isset($can_read[0]['u_readpm'])) ? array() : $can_read[0]['u_readpm'];
		$cannot_read_list = array_diff(array_keys($address_list['u']), $can_read);
		if (!empty($cannot_read_list))
		{
			foreach ($cannot_read_list as $cannot_read)
			{
				unset($address_list['u'][$cannot_read]);
			}

			$error[] = $user->lang['PM_USERS_REMOVED_NO_PERMISSION'];
		}

		// Check if users are banned
		$banned_user_list = phpbb_get_banned_user_ids(array_keys($address_list['u']), false);
		if (!empty($banned_user_list))
		{
			foreach ($banned_user_list as $banned_user)
			{
				unset($address_list['u'][$banned_user]);
			}

			$error[] = $user->lang['PM_USERS_REMOVED_NO_PERMISSION'];
		}
	}

	/**
	* Event for additional message list actions
	*
	* @event core.message_list_actions
	* @var	array	address_list		The assoc array with the recipient user/group ids
	* @var	array	error				The array containing error data
	* @var	bool	remove_u			The variable for removing a user
	* @var	bool	remove_g			The variable for removing a group
	* @var	bool	add_to				The variable for adding a user to the [TO] field
	* @var	bool	add_bcc				The variable for adding a user to the [BCC] field
	* @since 3.2.4-RC1
	*/
	$vars = array('address_list', 'error', 'remove_u', 'remove_g', 'add_to', 'add_bcc');
	extract($phpbb_dispatcher->trigger_event('core.message_list_actions', compact($vars)));
}

/**
* Build the hidden field for the recipients. Needed, as the variable is not read via $request->variable().
*/
function build_address_field($address_list)
{
	$s_hidden_address_field = '';
	foreach ($address_list as $type => $adr_ary)
	{
		foreach ($adr_ary as $id => $field)
		{
			$s_hidden_address_field .= '<input type="hidden" name="address_list[' . (($type == 'u') ? 'u' : 'g') . '][' . (int) $id . ']" value="' . (($field == 'to') ? 'to' : 'bcc') . '" />';
		}
	}
	return $s_hidden_address_field;
}

/**
* Return number of private message recipients
*/
function num_recipients($address_list)
{
	$num_recipients = 0;

	foreach ($address_list as $field => $adr_ary)
	{
		$num_recipients += count($adr_ary);
	}

	return $num_recipients;
}

/**
* Get number of 'num_recipients' recipients from first position
*/
function get_recipients($address_list, $num_recipients = 1)
{
	$recipient = array();

	$count = 0;
	foreach ($address_list as $field => $adr_ary)
	{
		foreach ($adr_ary as $id => $type)
		{
			if ($count >= $num_recipients)
			{
				break 2;
			}
			$recipient[$field][$id] = $type;
			$count++;
		}
	}

	return $recipient;
}
