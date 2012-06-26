<?php
/*
Class to support plugins:
Adding settings, changing templates, adding settings groups, adding templates - installing and deinstalling.
"A few steps to clean code..."
(c) 2011 by Victor
Website: http://www.victor.org.pl/support_class
License: Free to use, redistribute, BUT don't modify.
*/

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

/* idk why but need to make it global */
global $mybb, $db, /* COMPATIBLITY */ $ps;

/*function plugin_support_update()
{
	global $cache, $mybb, $datainfo;
	
	$datainfo = array(
		"bburl" => $mybb->settings['bburl'],
		"adminemail" => $mybb->settings['adminemail']
	);
	
	$datainfo['plugins'] = $cache->read("plugins");
	
	$ch = curl_init();
	
	curl_setopt($ch, CURLOPT_URL, "http://www.victor.org.pl/support_class/");
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, array("datainfo" => json_encode($datainfo)));

	$return = curl_exec($ch);
	
	if ($return)
	{
		flash_message($return, "success");
	}

	curl_close($ch);
}

$plugins->add_hook("admin_load", "plugin_support_update");*/

class plugin_support {
	private $name;
	protected $prefix;

	private $mybb;
	
	// Next time should be changed to private - TAKE CARE COMPATIBILITY
	public $db;
	private $lang;

	private $plugin_info;

	private $settings = array();
	private $settingsgdesc = '';
	private $tchanges = array();
	private $newts = array();

	public function __construct($name, $mybb, $db, $lang = false)
	{
		$this->name = (string)strtolower($name);
		$this->prefix = $this->name . "_";

		$this->plugin_info = call_user_func($this->prefix . "info");
		
		$this->mybb = $mybb;
		$this->db = $db;
		
		$this->lang = $lang;
	}

	public function setSettingsGDesc($desc)
	{
		$this->settingsgdesc = $desc;
	}

	public function addSetting($name, $title, $value = "", $description = "", $optionscode = "text")
	{
		if ( ! $title) {
			$title = $this->lang->{$this->name . '_' . $name . '_title'};
		}
		
		if ( ! $description)
		{
			$description = $this->lang->{$this->name . '_' . $name . '_description'};
		}
		
		$this->settings[] =
			array(
				"name" => $name,
				"title" => $title,
				"value" => $value,
				"description" => $this->db->escape_string($description),
				"optionscode" => $optionscode
			);
	}

	public function addTemplateChange($title, $what, $on, $type = "after")
	{
		$this->tchanges[] =
			array(
				"title" => $title,
				"what" => $what,
				"on" => $on,
				"type" => $type
			);
	}

	public function addNewTemplate($title, $template)
	{
		$this->newts[] =
			array(
				"title" => $title,
				"template" => $template
			);
	}
			

	public function is_installed()
	{
		$query = $this->db->simple_select("settinggroups", "name", "name = '".$this->name."'");

		if ( ! $this->db->num_rows($query))
		{
			return false;
		}
		else
		{
			return true;
		}
	}

	public function install()
	{
		if (!in_array("curl", get_loaded_extensions()))
		{
			flash_message("CURL extension hasn't been loaded.", "error");
			admin_redirect("index.php?module=config-plugins");
		}
		
		# SETTINGSGROUP
		$last_order = $this->db->simple_select("settinggroups", "disporder", "", array('order_by' => 'disporder', 'order_dir' => 'DESC', 'limit' => '1'));
	
		if (count($this->settings) > 0)
		{
			$settinggroup = array(
				"gid" => NULL,
				"name" => strtolower($this->name),
				"title" => $this->plugin_info['name'],
				"description" => "Settings of " . $this->plugin_info['name'] . ". " . $this->db->escape_string($this->settingsgdesc),
				"disporder" => ($this->db->fetch_field($last_order, "disporder") + 1),
			);
			$gid = $this->db->insert_query("settinggroups", $settinggroup);

			# SETTINGS
			$disp = 1;
			foreach ($this->settings as $setting)
			{
				$additional = array(
					"sid" => NULL,
					"disporder" => $disp,
					"gid" => $gid
				);

				$setting = array_merge($setting, $additional);
				$setting['name'] = $this->prefix.$setting['name'];

				$this->db->insert_query("settings", $setting);

				$disp++;
			}
		}
		
		rebuild_settings();
		
		# NEW TEMPLATES
		foreach ($this->newts as $newt)
		{
			$additional = array(
				"tid" => NULL,
				"sid" => "-1",
				"version" => "1600",
				"status" => NULL,
				"dateline" => time()
			);

			$newt = array_merge($newt, $additional);
			$newt['title'] = $this->prefix.$newt['title'];
			$newt['template'] = $this->db->escape_string($newt['template']);

			$this->db->insert_query("templates", $newt);

			$disp++;
		}
	}

	public function uninstall()
	{
		$this->db->delete_query("settinggroups", "name = '".$this->name."'");
		$this->db->delete_query("settings", "name LIKE '".$this->prefix."%'");
		$this->db->delete_query("templates", "title LIKE '".$this->prefix."%'");

		rebuild_settings();
	}

	public function activate()
	{
		require MYBB_ROOT."inc/adminfunctions_templates.php";

		# TEMPLATE CHANGES
		foreach ($this->tchanges as $tchange)
		{
			if ($tchange['type'] == "after")
			{
				find_replace_templatesets($tchange['title'], "#".preg_quote($tchange['what'])."#", "$0" . $tchange['on']);
			}
		}
	}

	public function deactivate()
	{
		require MYBB_ROOT."inc/adminfunctions_templates.php";

		foreach ($this->tchanges as $tchange)
		{
			if ($tchange['type'] == "after")
			{
				find_replace_templatesets($tchange['title'], "#".preg_quote($tchange['on'])."#", "", 0);
			}
		}
	}
	
	public function close()
	{
		/* deleted in previous (<1.4 @ 08.08.2011) version */
	}
}
?>