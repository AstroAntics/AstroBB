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

namespace phpbb\notification\type;

/**
* CUSTOM
* This class handles notifications for PM bans given to users.
*/

class pm_banned extends \phpbb\notification\type\pm
{
    // Return notification type
    public function get_type()
    {
        return 'notification.type.pm_banned';
    }
    
    // Return CSS style class
    public function get_style_class()
    {
        return 'notification-pm-banned';
    }
    
    // Language key
    protected $language_key = 'NOTIFICATION_PM_BANNED';
    
    // Permissions (just use the default moderator suite if they can't use it with m_pm_ban.)
    protected $permission = 'm_pm_ban';
    protected $base_permission = 'm_';
    
    public static $notification_option = [
        'id'    => 'notification.type.pm_banned',
        'lang'  => 'NOTIFICATION_PM_BANNED',
        // Do not specify a group as any group should be able to receive the PM ban notification
    ];
    
    public function is_available()
    {
        // return $this->config['allow_mod_pm_bans'];
        return true;
    }
    
    // Get target user ID
    public function get_user_id($user)
    {
        return (int) $user['user_id'];
    }
    
    // Get target user avatar
    public function get_user_avatar($user)
    {
        return $this->user_loader->get_avatar($this->get_data($user_id), false, true);
    }
    
    // Do some more stuff later.
    
}

?>
