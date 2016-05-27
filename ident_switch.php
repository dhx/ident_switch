<?php
/**
 * Identities IMAP
 *
 * This plugin allows fast switching between accounts.
 *
 * @version 1.0
 * @author Boris Gulay
 * @url 
 */
class ident_switch extends rcube_plugin
{
	public $task='?(?!login|logout).*';

	private $table = 'ident_switch';
	private $my_postfix = '_iswitch';

	// Flags user in database
	private $db_enabled = 1;
	private $db_secure = 2;

	function init()
	{
		$this->add_hook('startup', array($this, 'on_startup'));
		$this->add_hook('render_page', array($this, 'on_render_page'));
		$this->add_hook('smtp_connect', array($this, 'on_smtp_connect'));
		$this->add_hook('identity_form', array($this, 'on_identity_form'));
		$this->add_hook('identity_update', array($this, 'on_identity_update'));
		$this->add_hook('identity_delete', array($this, 'on_identity_delete'));
		$this->add_hook('template_object_composeheaders', array($this, 'on_template_object_composeheaders'));

		$this->register_action('plugin.ident_switch.switch', array($this, 'on_switch'));
	}

	function on_startup($args)
	{
		$rc = rcmail::get_instance();
		error_log('Task: ' . $args['task'] . ', action: ' . $args['action']);

		if (strcasecmp($rc->user->data['username'], $_SESSION['username']) !== 1)
		{ // We are impersonating
			$rc->config->set('imap_cache', null);
			$rc->config->set('messages_cache', false);

			if ($args['task'] == 'mail')
			{
				$this->add_texts('localization/');
				$rc->config->set('create_default_folders', false);
			}
		}

		return $args;
	}

	function on_render_page($args)
	{
		$rc = rcmail::get_instance();
		error_log('Template: ' . $args['template']);

		// Get list of alternative accounts
		$sOpt = '';
		$sql = 'SELECT id, label, username FROM ' . $rc->db->table_name($this->table) . ' WHERE user_id = ? AND flags & ? > 0';
		$q = $rc->db->query($sql, $rc->user->data['user_id'], $this->db_enabled);
		while ($r = $rc->db->fetch_assoc($q))
		{
			$opts = array('value' => $r['id']);
			if (strcasecmp($_SESSION['username'], $r['username']) === 0)
				$opts['selected'] = 'selected';

			// Make label
			$lbl = $r['label'];
			if (!$lbl)
			{
				if (strpos($r['username'], '@') === false)
					$lbl = $r['username'] . '@' . ($r['host'] ? $r['host'] : 'localhost');
				else
					$lbl = $r['username'];
			}

			$sOpt .= html::tag(
				'option',
				$opts,
				rcube::Q($lbl)
			);
		}

		// Render UI if user has extra accounts
		if (!empty($sOpt))
		{
			// Add main account
			$opts = array('value' => -1);
			if (strcasecmp($_SESSION['username'], $rc->user->data['username']) === 0)
				$opts['selected'] = 'selected';

			$sOpt = html::tag(
				'option',
				$opts,
				$_SESSION['global_alias'] ? $_SESSION['global_alias'] : $rc->user->data['username']
			) . $sOpt;

			$this->include_script('ident_switch.js');
			$sw = html::tag(
				'select', 
				array(
					'id' => 'plugin-ident_switch-account',
					'style' => 'display: none;',
					'onchange' => 'plugin_switchIdent_switch(this.value);',
				),
				$sOpt
			);
			$rc->output->add_footer($sw);
		}

		return $args;
	}

	function on_smtp_connect($args)
	{
		$rc = rcmail::get_instance();

		// TODO: Rewrite with full settings!

		if (strcasecmp($rc->user->data['username'], $_SESSION['username']) !== 1)
		{
			if ($args['smtp_user'] == '%u')
				$args['smtp_user'] = $rc->user->data['username'];
			if ($args['smtp_pass'] == '%p')
				$args['smtp_pass'] = $rc->decrypt($_SESSION['password' . $this->my_postfix]);
		}

		return $args;
	}

	function on_identity_form($args)
	{
		$rc = rcmail::get_instance();

		// Do now show options for default identity
		if (strcasecmp($args['record']['email'], $rc->user->data['username']) === 0)
			return $args;

		$this->add_texts('localization');

		// Create our field set
		$args['form']['ident_switch'] = array(
			'name' => $this->gettext('form.caption'),
			'content' => array(
				'ident_switch.form.enabled' => array('type' => 'checkbox', 'onchange' => 'plugin_switchIdent_enabled_onChange();'),
				'ident_switch.form.label' => array('type' => 'text', 'size' => 32),
				'ident_switch.form.host' => array('type' => 'text', 'size' => 64),
				'ident_switch.form.secure' => array('type' => 'checkbox'),
				'ident_switch.form.port' => array('type' => 'text', 'size' => 5),
				'ident_switch.form.username' => array('type' => 'text', 'size' => 64),
				'ident_switch.form.password' => array('type' => 'password', 'size' => 64),
				'ident_switch.form.delimiter' => array('type' => 'text', 'size' => 1),
			),
		);

		// Load data if exists
		if (isset($args['record']['identity_id']))
		{
			$sql = 'SELECT * FROM ' . $rc->db->table_name($this->table) . ' WHERE iid = ? AND user_id = ?';
			$q = $rc->db->query($sql, $args['record']['identity_id'], $rc->user->ID);
			$r = $rc->db->fetch_assoc($q);
			if ($r)
			{
				foreach ($r as $k => $v)
					$args['record']['ident_switch.form.' . $k] = $v;

				// Parse flags
				if ($r['flags'] & $this->db_enabled)
					$args['record']['ident_switch.form.enabled'] = true;
				if ($r['flags'] & $this->db_secure)
					$args['record']['ident_switch.form.secure'] = true;
			}
		}

		return $args;
	}

	function on_identity_update($args)
	{
		$rc = rcmail::get_instance();

		$this->add_texts('localization');

		// Check field values
		$noErrors = false;

		$fLabel = self::ntrim(get_input_value('_ident_switch_form_label', RCUBE_INPUT_POST));
		if (strlen($fLabel) > 32)
			$rc->output->show_message('ident_switch.err.label.long', 'error');
		else
		{
			$fHost = self::ntrim(get_input_value('_ident_switch_form_host', RCUBE_INPUT_POST));
			if (strlen($fHost) > 64)
				$rc->output->show_message('ident_switch.err.host.long', 'error');
			else
			{
				$fPort = self::ntrim(get_input_value('_ident_switch_form_port', RCUBE_INPUT_POST));
				if ($fPort && !ctype_digit($fPort))
					$rc->output->show_message('ident_switch.err.port.num', 'error');
				else
				{
					if ($fPort && ($fPort <= 0 || $fPort > 65535))
						$rc->output->show_message('ident_switch.err.port.range', 'error');
					else
					{
						$fUser = self::ntrim(get_input_value('_ident_switch_form_username', RCUBE_INPUT_POST));
						if (strlen($fUser) > 64)
							$rc->output->show_message('ident_switch.err.user.long', 'error');
						else
						{
							if (!$fUser)
								$rc->output->show_message('ident_switch.err.user.empty', 'error');
							else
							{
								$fDelim = self::ntrim(get_input_value('_ident_switch_form_delimiter', RCUBE_INPUT_POST));
								if (strlen($fDelim) > 1)
									$rc->output->show_message('ident_switch.err.delim.long', 'error');
								else
									$noErrors = true;
							}
						}
					}
				}
			}
		}

		if (!$noErrors)
		{
			//$rc->output->send();
			$args['abort'] = true;
			return $args;
		}

		$sql = 'SELECT password FROM ' . $rc->db->table_name($this->table) . ' WHERE iid = ? AND user_id = ?';
		$q = $rc->db->query($sql, $args['id'], $rc->user->ID);
		$r = $rc->db->fetch_assoc($q);
		if ($r)
		{ // Record already exists, will update it
			$sql = 'UPDATE ' .
				$rc->db->table_name($this->table) .
				' SET flags = ?, label = ?, host = ?, port = ?, username = ?, password = ?, delimiter = ?, user_id = ?, iid = ?';
		}
		else
		{ // No record exists, create new one
			$sql = 'INSERT INTO ' .
				$rc->db->table_name($this->table) .
				'(flags, label, host, port, username, password, delimiter, user_id, iid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
		}

		// Process boolean fields
		$flags = 0;
		if (get_input_value('_ident_switch_form_enabled', RCUBE_INPUT_POST))
			$flags |= $this->db_enabled;
		if (get_input_value('_ident_switch_form_secure', RCUBE_INPUT_POST))
			$flags |= $this->db_secure;

		// Do we need to update pwd?
		$fPass = get_input_value('_ident_switch_form_password', RCUBE_INPUT_POST);
		if ($fPass != $r['password'])
			$fPass = $rc->encrypt($fPass);

		$rc->db->query(
			$sql,
			$flags,
			$fLabel,
			$fHost,
			$fPort,
			$fUser,
			$fPass,
			$fDelim,
			$rc->user->ID,
			$args['id']
		);

		return $args;
	}

	function on_identity_delete($args)
	{
		$rc = rcmail::get_instance();

		$sql = 'DELETE FROM ' . $rc->db->table_name($this->table) . ' WHERE iid = ? AND user_id = ?';
		$q = $rc->db->query($sql, $args['id'], $rc->user->ID);

		if ($rc->db->affected_rows($q))
			$rc->write_log($this->my_log, 'Deleted associated information for identity with ID = ' . $args['id'] . '.');

		return $args;
	}

	function on_template_object_composeheaders($args)
	{
		if ($args['id'] == '_from')
		{
			$rc = rcmail::get_instance();
			if (strcasecmp($_SESSION['username'], $rc->user->data['username']) !== 0)
			{
				if (isset($_SESSION['iid' . $this->my_postfix]))
					$rc->output->add_script('plugin_switchIdent_fixIdent(' . $_SESSION['iid' . $this->my_postfix] . ');', 'docready');
				else
					$rc->write_log($this->my_log, 'Special session variable with active identity ID not found.');
			}
		}
	}

	function on_switch()
	{
		$rc = rcmail::get_instance();

		$my_postfix_len = strlen($this->my_postfix);
		$identId = rcube_utils::get_input_value('_ident-id', RCUBE_INPUT_POST);

		if (-1 == $identId)
		{ // Switch to main account
			$rc->write_log($this->my_log, 'Switching mailbox back to default.');

			foreach ($_SESSION as $k => $v)
			{
				if (strncasecmp($k, 'storage', 7) === 0 && substr_compare($k, $this->my_postfix, -$my_postfix_len, $my_postfix_len) === 0)
				{
					$_SESSION[$k] = $_SESSION[$k . $this->my_postfix];
					$rc->session->remove($k . $this->my_postfix);
				}
			}
			$_SESSION['username'] = $rc->user->data['username'];
			$_SESSION['password'] = $_SESSION['password' . $this->my_postfix];
			$_SESSION['iid' . $this->my_postfix] = -1;
		}
		else
		{
			$sql = 'SELECT host, flags, port, username, password, iid FROM ' . $rc->db->table_name($this->table) . ' WHERE id = ? AND user_id = ?';
			$q = $rc->db->query($sql, $identId ,$rc->user->ID);
			$r = $rc->db->fetch_assoc($q);
			if (is_array($r))
			{
				$rc->write_log(
					$this->my_log,
					'Switching mailbox to one for identity with ID = ' . $r['iid'] . ' (username = \'' . $r['username'] . '\').'
				);

				$port = $r['port'];
				$ssl = false;
				if ($r['flags'] & $this->db_secure)
				{
					$ssl = true;
					if (!$port)
						$port = 993; // Default SSL/TLS port here!
				}
				if (!$port)
					$port = 143; // Default port here!

				// If we are in default account now
				// save everything with STORAGE
				if ($_SESSION['username'] == $rc->user->data['username'])
				{
					foreach ($_SESSION as $k => $v)
					{
						if (strncasecmp($k, 'storage', 7) === 0 && substr_compare($k, $this->my_postfix, -$my_postfix_len, $my_postfix_len) !== 0)
						{
							$_SESSION[$k . $this->my_postfix] = $_SESSION[$k];
                            $rc->session->remove($k);
						}
					}
				}
				$_SESSION['password' . $this->my_postfix] = $_SESSION['password'];

				$_SESSION['storage_host'] = $r['host'] ? $r['host'] : 'localhost'; // Default host here!
				$_SESSION['storage_ssl'] = $ssl;
				$_SESSION['storage_port'] = $port;
				$_SESSION['username'] = $r['username'];
				$_SESSION['password'] = $r['password'];
				$_SESSION['iid' . $this->my_postfix] = $r['iid'];

				$rc->session->remove('folders');
			}
			else
			{
				// TODO: Show message in browser
				$rc->write_log($this->my_log, 'Requested remote mailbox with ID = ' . $identId . ' not found.');
				return;
			}
		}

		$rc->output->redirect(
			array(
				'_task' => 'mail',
				'_mbox' => rcube_utils::get_input_value('_mbox', RCUBE_INPUT_GET),
			)
		);
	}

	protected static function ntrim($str)
	{
		if (is_null($str))
			return $str;

		$s = trim($str);
		if (!$s)
			return null;

		return $s;
	}	
}
