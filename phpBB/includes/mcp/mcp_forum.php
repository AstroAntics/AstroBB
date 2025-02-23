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
* MCP Forum View
*/
function mcp_forum_view($id, $mode, $action, $forum_info)
{
	global $template, $db, $user, $auth, $cache, $module;
	global $phpEx, $phpbb_root_path, $config;
	global $request, $phpbb_dispatcher, $phpbb_container;

	$user->add_lang(array('viewtopic', 'viewforum'));

	include_once($phpbb_root_path . 'includes/functions_display.' . $phpEx);

	// merge_topic is the quickmod action, merge_topics is the mcp_forum action, and merge_select is the mcp_topic action
	$merge_select = ($action == 'merge_select' || $action == 'merge_topic' || $action == 'merge_topics') ? true : false;

	$forum_id			= $forum_info['forum_id'];
	$start				= $request->variable('start', 0);
	$topic_id_list		= $request->variable('topic_id_list', array(0));
	$post_id_list		= $request->variable('post_id_list', array(0));
	$source_topic_ids	= array($request->variable('t', 0));
	$to_topic_id		= $request->variable('to_topic_id', 0);

	$url_extra = '';
	$url_extra .= ($forum_id) ? "&amp;f=$forum_id" : '';
	$url_extra .= ($GLOBALS['topic_id']) ? '&amp;t=' . $GLOBALS['topic_id'] : '';
	$url_extra .= ($GLOBALS['post_id']) ? '&amp;p=' . $GLOBALS['post_id'] : '';
	$url_extra .= ($GLOBALS['user_id']) ? '&amp;u=' . $GLOBALS['user_id'] : '';

	$url = append_sid("{$phpbb_root_path}mcp.$phpEx?$url_extra");

	add_form_key('mcp_forum');

	// Resync Topics
	switch ($action)
	{
		case 'resync':
			if (!check_form_key('mcp_forum'))
			{
				trigger_error('FORM_INVALID');
			}

			$topic_ids = $request->variable('topic_id_list', array(0));
			mcp_resync_topics($topic_ids);
		break;

		case 'merge_topics':
			$source_topic_ids = $topic_id_list;
		case 'merge_topic':
			if ($to_topic_id)
			{
				merge_topics($forum_id, $source_topic_ids, $to_topic_id);
			}
		break;
	}

	/**
	* Get some data in order to execute other actions.
	*
	* @event core.mcp_forum_view_before
	* @var	string	action				The action
	* @var	array	forum_info			Array with forum infos
	* @var	int		start				Start value
	* @var	array	topic_id_list		Array of topics ids
	* @var	array	post_id_list		Array of posts ids
	* @var	array	source_topic_ids	Array of source topics ids
	* @var	int		to_topic_id			Array of destination topics ids
	* @since 3.1.6-RC1
	*/
	$vars = array(
		'action',
		'forum_info',
		'start',
		'topic_id_list',
		'post_id_list',
		'source_topic_ids',
		'to_topic_id',
	);
	extract($phpbb_dispatcher->trigger_event('core.mcp_forum_view_before', compact($vars)));

	/* @var $pagination \phpbb\pagination */
	$pagination = $phpbb_container->get('pagination');

	$selected_ids = '';
	if (count($post_id_list) && $action != 'merge_topics')
	{
		foreach ($post_id_list as $num => $post_id)
		{
			$selected_ids .= '&amp;post_id_list[' . $num . ']=' . $post_id;
		}
	}
	else if (count($topic_id_list) && $action == 'merge_topics')
	{
		foreach ($topic_id_list as $num => $topic_id)
		{
			$selected_ids .= '&amp;topic_id_list[' . $num . ']=' . $topic_id;
		}
	}

	make_jumpbox($url . "&amp;i=$id&amp;action=$action&amp;mode=$mode" . (($merge_select) ? $selected_ids : ''), $forum_id, false, 'm_', true);

	$topics_per_page = ($forum_info['forum_topics_per_page']) ? $forum_info['forum_topics_per_page'] : $config['topics_per_page'];

	$sort_days = $total = 0;
	$sort_key = $sort_dir = '';
	$sort_by_sql = $sort_order_sql = array();
	phpbb_mcp_sorting('viewforum', $sort_days, $sort_key, $sort_dir, $sort_by_sql, $sort_order_sql, $total, $forum_id);

	$forum_topics = ($total == -1) ? $forum_info['forum_topics_approved'] : $total;
	$limit_time_sql = ($sort_days) ? 'AND t.topic_last_post_time >= ' . (time() - ($sort_days * 86400)) : '';

	$base_url = $url . "&amp;i=$id&amp;action=$action&amp;mode=$mode&amp;sd=$sort_dir&amp;sk=$sort_key&amp;st=$sort_days" . (($merge_select) ? $selected_ids : '');
	$pagination->generate_template_pagination($base_url, 'pagination', 'start', $forum_topics, $topics_per_page, $start);

	$template->assign_vars(array(
		'ACTION'				=> $action,
		'FORUM_NAME'			=> $forum_info['forum_name'],
		'FORUM_DESCRIPTION'		=> generate_text_for_display($forum_info['forum_desc'], $forum_info['forum_desc_uid'], $forum_info['forum_desc_bitfield'], $forum_info['forum_desc_options']),

		'REPORTED_IMG'			=> $user->img('icon_topic_reported', 'TOPIC_REPORTED'),
		'UNAPPROVED_IMG'		=> $user->img('icon_topic_unapproved', 'TOPIC_UNAPPROVED'),
		'LAST_POST_IMG'			=> $user->img('icon_topic_latest', 'VIEW_LATEST_POST'),
		'NEWEST_POST_IMG'		=> $user->img('icon_topic_newest', 'VIEW_NEWEST_POST'),

		'S_CAN_REPORT'			=> $auth->acl_get('m_report', $forum_id),
		'S_CAN_DELETE'			=> $auth->acl_get('m_delete', $forum_id),
		'S_CAN_RESTORE'			=> $auth->acl_get('m_approve', $forum_id),
		'S_CAN_MERGE'			=> $auth->acl_get('m_merge', $forum_id),
		'S_CAN_MOVE'			=> $auth->acl_get('m_move', $forum_id),
		'S_CAN_FORK'			=> $auth->acl_get('m_', $forum_id),
		'S_CAN_LOCK'			=> $auth->acl_get('m_lock', $forum_id),
		'S_CAN_SYNC'			=> $auth->acl_get('m_', $forum_id),
		'S_CAN_APPROVE'			=> $auth->acl_get('m_approve', $forum_id),
		'S_MERGE_SELECT'		=> ($merge_select) ? true : false,
		'S_CAN_MAKE_NORMAL'		=> $auth->acl_gets('f_sticky', 'f_announce', 'f_announce_global', $forum_id),
		'S_CAN_MAKE_STICKY'		=> $auth->acl_get('f_sticky', $forum_id),
		'S_CAN_MAKE_ANNOUNCE'	=> $auth->acl_get('f_announce', $forum_id),
		'S_CAN_MAKE_ANNOUNCE_GLOBAL'	=> $auth->acl_get('f_announce_global', $forum_id),

		'U_VIEW_FORUM'			=> append_sid("{$phpbb_root_path}viewforum.$phpEx", 'f=' . $forum_id),
		'U_VIEW_FORUM_LOGS'		=> ($auth->acl_gets('a_', 'm_', $forum_id) && $module->loaded('logs')) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=logs&amp;mode=forum_logs&amp;f=' . $forum_id) : '',

		'S_MCP_ACTION'			=> $url . "&amp;i=$id&amp;forum_action=$action&amp;mode=$mode&amp;start=$start" . (($merge_select) ? $selected_ids : ''),

		'TOTAL_TOPICS'			=> $user->lang('VIEW_FORUM_TOPICS', (int) $forum_topics),
	));

	// Grab icons
	$icons = $cache->obtain_icons();

	$topic_rows = array();

	if ($config['load_db_lastread'])
	{
		$sql_read_tracking['LEFT_JOIN'][] = ['FROM' => [TOPICS_TRACK_TABLE => 'tt'], 'ON' => 'tt.topic_id = t.topic_id AND tt.user_id = ' . $user->data['user_id']];
		$sql_read_tracking['SELECT'] = ', tt.mark_time';
	}
	else
	{
		$sql_read_tracking['LEFT_JOIN'] = [];
		$sql_read_tracking['SELECT'] = '';
	}

	/* @var $phpbb_content_visibility \phpbb\content_visibility */
	$phpbb_content_visibility = $phpbb_container->get('content.visibility');

	$sql = 'SELECT t.topic_id
		FROM ' . TOPICS_TABLE . ' t
		WHERE t.forum_id = ' . $forum_id . '
			AND ' . $phpbb_content_visibility->get_visibility_sql('topic', $forum_id, 't.') . "
			$limit_time_sql
		ORDER BY t.topic_type DESC, $sort_order_sql";

	/**
	* Modify SQL query before MCP forum view topic list is queried
	*
	* @event core.mcp_view_forum_modify_sql
	* @var	string	sql			SQL query for forum view topic list
	* @var	int	forum_id	ID of the forum
	* @var	string  limit_time_sql		SQL query part for limit time
	* @var	string  sort_order_sql		SQL query part for sort order
	* @var	int topics_per_page			Number of topics per page
	* @var	int start			Start value
	* @since 3.1.2-RC1
	*/
	$vars = array('sql', 'forum_id', 'limit_time_sql', 'sort_order_sql', 'topics_per_page', 'start');
	extract($phpbb_dispatcher->trigger_event('core.mcp_view_forum_modify_sql', compact($vars)));

	$result = $db->sql_query_limit($sql, $topics_per_page, $start);

	$topic_list = $topic_tracking_info = array();

	while ($row_ary = $db->sql_fetchrow($result))
	{
		$topic_list[] = $row_ary['topic_id'];
	}
	$db->sql_freeresult($result);

	$sql_ary = [
		'SELECT'	=> 't.*' . $sql_read_tracking['SELECT'],
		'FROM'		=> [TOPICS_TABLE => 't'],
		'LEFT_JOIN'	=> $sql_read_tracking['LEFT_JOIN'],
		'WHERE'		=> $db->sql_in_set('t.topic_id', $topic_list, false, true),
	];

	/**
	* Event to modify SQL query before MCP forum topic data is queried
	*
	* @event core.mcp_forum_topic_data_modify_sql
	* @var	array	sql_ary		SQL query array to get the MCP forum topic data
	* @var	int		forum_id	The forum ID
	* @var	array	topic_list	The array of MCP forum topic IDs
	*
	* @since 3.3.4-RC1
	*/
	$vars = [
		'sql_ary',
		'forum_id',
		'topic_list',
	];
	extract($phpbb_dispatcher->trigger_event('core.mcp_forum_topic_data_modify_sql', compact($vars)));

	$sql = $db->sql_build_query('SELECT', $sql_ary);
	$result = $db->sql_query($sql);
	while ($row_ary = $db->sql_fetchrow($result))
	{
		$topic_rows[$row_ary['topic_id']] = $row_ary;
	}
	$db->sql_freeresult($result);

	// If there is more than one page, but we have no topic list, then the start parameter is... erm... out of sync
	if (!count($topic_list) && $forum_topics && $start > 0)
	{
		redirect($url . "&amp;i=$id&amp;action=$action&amp;mode=$mode");
	}

	// Get topic tracking info
	if (count($topic_list))
	{
		if ($config['load_db_lastread'])
		{
			$topic_tracking_info = get_topic_tracking($forum_id, $topic_list, $topic_rows, array($forum_id => $forum_info['mark_time']));
		}
		else
		{
			$topic_tracking_info = get_complete_topic_tracking($forum_id, $topic_list);
		}
	}

	foreach ($topic_list as $topic_id)
	{
		$row_ary = &$topic_rows[$topic_id];

		$replies = $phpbb_content_visibility->get_count('topic_posts', $row_ary, $forum_id) - 1;

		if ($row_ary['topic_status'] == ITEM_MOVED)
		{
			$unread_topic = false;
		}
		else
		{
			$unread_topic = (isset($topic_tracking_info[$topic_id]) && $row_ary['topic_last_post_time'] > $topic_tracking_info[$topic_id]) ? true : false;
		}

		// Get folder img, topic status/type related information
		$folder_img = $folder_alt = $topic_type = '';
		topic_status($row_ary, $replies, $unread_topic, $folder_img, $folder_alt, $topic_type);

		$topic_title = censor_text($row_ary['topic_title']);

		$topic_unapproved = (($row_ary['topic_visibility'] == ITEM_UNAPPROVED || $row_ary['topic_visibility'] == ITEM_REAPPROVE)  && $auth->acl_get('m_approve', $row_ary['forum_id'])) ? true : false;
		$posts_unapproved = ($row_ary['topic_visibility'] == ITEM_APPROVED && $row_ary['topic_posts_unapproved'] && $auth->acl_get('m_approve', $row_ary['forum_id'])) ? true : false;
		$topic_deleted = $row_ary['topic_visibility'] == ITEM_DELETED;
		$u_mcp_queue = ($topic_unapproved || $posts_unapproved) ? $url . '&amp;i=queue&amp;mode=' . (($topic_unapproved) ? 'approve_details' : 'unapproved_posts') . '&amp;t=' . $row_ary['topic_id'] : '';
		$u_mcp_queue = (!$u_mcp_queue && $topic_deleted) ? $url . '&amp;i=queue&amp;mode=deleted_topics&amp;t=' . $topic_id : $u_mcp_queue;

		$topic_row = array(
			'ATTACH_ICON_IMG'		=> ($auth->acl_get('u_download') && $auth->acl_get('f_download', $row_ary['forum_id']) && $row_ary['topic_attachment']) ? $user->img('icon_topic_attach', $user->lang['TOTAL_ATTACHMENTS']) : '',
			'TOPIC_IMG_STYLE'		=> $folder_img,
			'TOPIC_FOLDER_IMG'		=> $user->img($folder_img, $folder_alt),
			'TOPIC_FOLDER_IMG_ALT'	=> $user->lang[$folder_alt],
			'TOPIC_ICON_IMG'		=> (!empty($icons[$row_ary['icon_id']])) ? $icons[$row_ary['icon_id']]['img'] : '',
			'TOPIC_ICON_IMG_WIDTH'	=> (!empty($icons[$row_ary['icon_id']])) ? $icons[$row_ary['icon_id']]['width'] : '',
			'TOPIC_ICON_IMG_HEIGHT'	=> (!empty($icons[$row_ary['icon_id']])) ? $icons[$row_ary['icon_id']]['height'] : '',
			'UNAPPROVED_IMG'		=> ($topic_unapproved || $posts_unapproved) ? $user->img('icon_topic_unapproved', ($topic_unapproved) ? 'TOPIC_UNAPPROVED' : 'POSTS_UNAPPROVED') : '',
			'DELETED_IMG'			=> ($topic_deleted) ? $user->img('icon_topic_deleted', 'TOPIC_DELETED') : '',
			'S_POST_ANNOUNCE'		=> $row_ary['topic_type'] == POST_ANNOUNCE,
			'S_POST_GLOBAL'			=> $row_ary['topic_type'] == POST_GLOBAL,
			'S_POST_STICKY'			=> $row_ary['topic_type'] == POST_STICKY,
			'S_TOPIC_LOCKED'		=> $row_ary['topic_status'] == ITEM_LOCKED,
			'S_TOPIC_MOVED'			=> $row_ary['topic_status'] == ITEM_MOVED,

			'TOPIC_AUTHOR'				=> get_username_string('username', $row_ary['topic_poster'], $row_ary['topic_first_poster_name'], $row_ary['topic_first_poster_colour']),
			'TOPIC_AUTHOR_COLOUR'		=> get_username_string('colour', $row_ary['topic_poster'], $row_ary['topic_first_poster_name'], $row_ary['topic_first_poster_colour']),
			'TOPIC_AUTHOR_FULL'			=> get_username_string('full', $row_ary['topic_poster'], $row_ary['topic_first_poster_name'], $row_ary['topic_first_poster_colour']),
			'U_TOPIC_AUTHOR'			=> get_username_string('profile', $row_ary['topic_poster'], $row_ary['topic_first_poster_name'], $row_ary['topic_first_poster_colour']),

			'LAST_POST_AUTHOR'			=> get_username_string('username', $row_ary['topic_last_poster_id'], $row_ary['topic_last_poster_name'], $row_ary['topic_last_poster_colour']),
			'LAST_POST_AUTHOR_COLOUR'	=> get_username_string('colour', $row_ary['topic_last_poster_id'], $row_ary['topic_last_poster_name'], $row_ary['topic_last_poster_colour']),
			'LAST_POST_AUTHOR_FULL'		=> get_username_string('full', $row_ary['topic_last_poster_id'], $row_ary['topic_last_poster_name'], $row_ary['topic_last_poster_colour']),
			'U_LAST_POST_AUTHOR'		=> get_username_string('profile', $row_ary['topic_last_poster_id'], $row_ary['topic_last_poster_name'], $row_ary['topic_last_poster_colour']),

			'TOPIC_TYPE'		=> $topic_type,
			'TOPIC_TITLE'		=> $topic_title,
			'REPLIES'			=> $phpbb_content_visibility->get_count('topic_posts', $row_ary, $row_ary['forum_id']) - 1,
			'LAST_POST_TIME'	=> $user->format_date($row_ary['topic_last_post_time']),
			'FIRST_POST_TIME'	=> $user->format_date($row_ary['topic_time']),
			'LAST_POST_SUBJECT'	=> $row_ary['topic_last_post_subject'],
			'LAST_VIEW_TIME'	=> $user->format_date($row_ary['topic_last_view_time']),

			'S_TOPIC_REPORTED'		=> (!empty($row_ary['topic_reported']) && empty($row_ary['topic_moved_id']) && $auth->acl_get('m_report', $row_ary['forum_id'])) ? true : false,
			'S_TOPIC_UNAPPROVED'	=> $topic_unapproved,
			'S_POSTS_UNAPPROVED'	=> $posts_unapproved,
			'S_TOPIC_DELETED'		=> $topic_deleted,
			'S_UNREAD_TOPIC'		=> $unread_topic,
		);

		if ($row_ary['topic_status'] == ITEM_MOVED)
		{
			$topic_row = array_merge($topic_row, array(
				'U_VIEW_TOPIC'		=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", "t={$row_ary['topic_moved_id']}"),
				'U_DELETE_TOPIC'	=> ($auth->acl_get('m_delete', $forum_id)) ? append_sid("{$phpbb_root_path}mcp.$phpEx", "i=$id&amp;f=$forum_id&amp;topic_id_list[]={$row_ary['topic_id']}&amp;mode=forum_view&amp;action=delete_topic") : '',
				'S_MOVED_TOPIC'		=> true,
				'TOPIC_ID'			=> $row_ary['topic_moved_id'],
			));
		}
		else
		{
			if ($action == 'merge_topic' || $action == 'merge_topics')
			{
				$u_select_topic = $url . "&amp;i=$id&amp;mode=forum_view&amp;action=$action&amp;to_topic_id=" . $row_ary['topic_id'] . $selected_ids;
			}
			else
			{
				$u_select_topic = $url . "&amp;i=$id&amp;mode=topic_view&amp;action=merge&amp;to_topic_id=" . $row_ary['topic_id'] . $selected_ids;
			}
			$topic_row = array_merge($topic_row, array(
				'U_VIEW_TOPIC'		=> append_sid("{$phpbb_root_path}mcp.$phpEx", "i=$id&amp;f=$forum_id&amp;t={$row_ary['topic_id']}&amp;mode=topic_view"),

				'S_SELECT_TOPIC'	=> ($merge_select && !in_array($row_ary['topic_id'], $source_topic_ids)) ? true : false,
				'U_SELECT_TOPIC'	=> $u_select_topic,
				'U_MCP_QUEUE'		=> $u_mcp_queue,
				'U_MCP_REPORT'		=> ($auth->acl_get('m_report', $forum_id)) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=main&amp;mode=topic_view&amp;t=' . $row_ary['topic_id'] . '&amp;action=reports') : '',
				'TOPIC_ID'			=> $row_ary['topic_id'],
				'S_TOPIC_CHECKED'	=> ($topic_id_list && in_array($row_ary['topic_id'], $topic_id_list)) ? true : false,
			));
		}

		$row = $row_ary;
		/**
		* Modify the topic data before it is assigned to the template in MCP
		*
		* @event core.mcp_view_forum_modify_topicrow
		* @var	array	row		Array with topic data
		* @var	array	topic_row	Template array with topic data
		* @since 3.1.0-a1
		*/
		$vars = array('row', 'topic_row');
		extract($phpbb_dispatcher->trigger_event('core.mcp_view_forum_modify_topicrow', compact($vars)));
		$row_ary = $row;
		unset($row);

		$template->assign_block_vars('topicrow', $topic_row);
	}
	unset($topic_rows);
}

/**
* Resync topics
*/
function mcp_resync_topics($topic_ids)
{
	global $db, $user, $phpbb_log, $request;

	if (!count($topic_ids))
	{
		trigger_error('NO_TOPIC_SELECTED');
	}

	if (!phpbb_check_ids($topic_ids, TOPICS_TABLE, 'topic_id', array('m_')))
	{
		return;
	}

	// Sync everything and perform extra checks separately
	sync('topic_reported', 'topic_id', $topic_ids, false, true);
	sync('topic_attachment', 'topic_id', $topic_ids, false, true);
	sync('topic', 'topic_id', $topic_ids, true, false);

	$sql = 'SELECT topic_id, forum_id, topic_title
		FROM ' . TOPICS_TABLE . '
		WHERE ' . $db->sql_in_set('topic_id', $topic_ids);
	$result = $db->sql_query($sql);

	// Log this action
	while ($row = $db->sql_fetchrow($result))
	{
		$phpbb_log->add('mod', $user->data['user_id'], $user->ip, 'LOG_TOPIC_RESYNC', false, array(
			'forum_id' => $row['forum_id'],
			'topic_id' => $row['topic_id'],
			$row['topic_title']
		));
	}
	$db->sql_freeresult($result);

	$msg = (count($topic_ids) == 1) ? $user->lang['TOPIC_RESYNC_SUCCESS'] : $user->lang['TOPICS_RESYNC_SUCCESS'];

	$redirect = $request->variable('redirect', $user->data['session_page']);

	meta_refresh(3, $redirect);
	trigger_error($msg . '<br /><br />' . sprintf($user->lang['RETURN_PAGE'], '<a href="' . $redirect . '">', '</a>'));

	return;
}

/**
* Merge selected topics into selected topic
*/
function merge_topics($forum_id, $topic_ids, $to_topic_id)
{
	global $db, $template, $user, $phpEx, $phpbb_root_path, $phpbb_log, $request, $phpbb_dispatcher;

	if (!count($topic_ids))
	{
		$template->assign_var('MESSAGE', $user->lang['NO_TOPIC_SELECTED']);
		return;
	}
	if (!$to_topic_id)
	{
		$template->assign_var('MESSAGE', $user->lang['NO_FINAL_TOPIC_SELECTED']);
		return;
	}

	$sync_topics = array_merge($topic_ids, array($to_topic_id));

	$all_topic_data = phpbb_get_topic_data($sync_topics, 'm_merge');

	if (!count($all_topic_data) || empty($all_topic_data[$to_topic_id]))
	{
		$template->assign_var('MESSAGE', $user->lang['NO_FINAL_TOPIC_SELECTED']);
		return;
	}

	$sync_forums = array();
	$topic_views = 0;
	foreach ($all_topic_data as $data)
	{
		$sync_forums[$data['forum_id']] = $data['forum_id'];
		$topic_views = max($topic_views, $data['topic_views']);
	}

	$to_topic_data = $all_topic_data[$to_topic_id];

	$post_id_list	= $request->variable('post_id_list', array(0));
	$start			= $request->variable('start', 0);

	if (!count($post_id_list) && count($topic_ids))
	{
		$sql = 'SELECT post_id
			FROM ' . POSTS_TABLE . '
			WHERE ' . $db->sql_in_set('topic_id', $topic_ids);
		$result = $db->sql_query($sql);

		$post_id_list = array();
		while ($row = $db->sql_fetchrow($result))
		{
			$post_id_list[] = $row['post_id'];
		}
		$db->sql_freeresult($result);
	}

	if (!count($post_id_list))
	{
		$template->assign_var('MESSAGE', $user->lang['NO_POST_SELECTED']);
		return;
	}

	if (!phpbb_check_ids($post_id_list, POSTS_TABLE, 'post_id', array('m_merge')))
	{
		return;
	}

	$redirect = $request->variable('redirect', "{$phpbb_root_path}mcp.$phpEx?f=$forum_id&amp;i=main&amp;mode=forum_view");

	$s_hidden_fields = build_hidden_fields(array(
		'i'				=> 'main',
		'f'				=> $forum_id,
		'post_id_list'	=> $post_id_list,
		'to_topic_id'	=> $to_topic_id,
		'mode'			=> 'forum_view',
		'action'		=> 'merge_topics',
		'start'			=> $start,
		'redirect'		=> $redirect,
		'topic_id_list'	=> $topic_ids)
	);
	$return_link = '';

	if (confirm_box(true))
	{
		$to_forum_id = $to_topic_data['forum_id'];

		move_posts($post_id_list, $to_topic_id, false);

		$phpbb_log->add('mod', $user->data['user_id'], $user->ip, 'LOG_MERGE', false, array(
			'forum_id' => $to_forum_id,
			'topic_id' => $to_topic_id,
			$to_topic_data['topic_title']
		));

		// Update topic views count
		$sql = 'UPDATE ' . TOPICS_TABLE . '
				SET topic_views = ' . $topic_views . '
				WHERE topic_id = ' . $to_topic_id;
		$db->sql_query($sql);

		// Message and return links
		$success_msg = 'POSTS_MERGED_SUCCESS';

		if (!function_exists('phpbb_update_rows_avoiding_duplicates_notify_status'))
		{
			include($phpbb_root_path . 'includes/functions_database_helper.' . $phpEx);
		}

		// Update the topic watch table.
		phpbb_update_rows_avoiding_duplicates_notify_status($db, TOPICS_WATCH_TABLE, 'topic_id', $topic_ids, $to_topic_id);

		// Update the bookmarks table.
		phpbb_update_rows_avoiding_duplicates($db, BOOKMARKS_TABLE, 'topic_id', $topic_ids, $to_topic_id);

		// Re-sync the topics and forums because the auto-sync was deactivated in the call of  move_posts()
		sync('topic_reported', 'topic_id', $sync_topics);
		sync('topic_attachment', 'topic_id', $sync_topics);
		sync('topic', 'topic_id', $sync_topics, true);
		sync('forum', 'forum_id', $sync_forums, true, true);

		// Link to the new topic
		$return_link .= (($return_link) ? '<br /><br />' : '') . sprintf($user->lang['RETURN_NEW_TOPIC'], '<a href="' . append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . $to_forum_id . '&amp;t=' . $to_topic_id) . '">', '</a>');
		$redirect = $request->variable('redirect', "{$phpbb_root_path}viewtopic.$phpEx?f=$to_forum_id&amp;t=$to_topic_id");
		$redirect = reapply_sid($redirect);

		/**
		 * Perform additional actions after merging topics.
		 *
		 * @event core.mcp_forum_merge_topics_after
		 * @var	array	all_topic_data			The data from all topics involved in the merge
		 * @var	int		to_topic_id				The ID of the topic into which the rest are merged
		 * @since 3.1.11-RC1
		 */
		$vars = array(
			'all_topic_data',
			'to_topic_id',
		);
		extract($phpbb_dispatcher->trigger_event('core.mcp_forum_merge_topics_after', compact($vars)));

		meta_refresh(3, $redirect);
		trigger_error($user->lang[$success_msg] . '<br /><br />' . $return_link);
	}
	else
	{
		confirm_box(false, 'MERGE_TOPICS', $s_hidden_fields);
	}
}
