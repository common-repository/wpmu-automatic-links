<?php
/*
Plugin Name: WPMU Automatic Links
Plugin URI: http://wordpress.org/extend/plugins/wpmu-automatic-links/
Description: This plugin will automatically create links from words. If you add word on master blog, all network wil lhave the replacement else it's just for one blog. Based on the exelent plugin automatic seo links from Emilio (http://emilio.aesinformatica.com)
Author: Benjamin Santalucia (ben@woow-fr.com)
Version: 1.1
Author URI: http://wordpress.org/extend/plugins/profile/ido8p
*/
if (!class_exists('WPMUAutomaticLinks')) {
	class WPMUAutomaticLinks{
		const ADMINISTRATOR = 8;
		const DOMAIN = 'WPMUAutomaticLinks';
		const MASTERBLOG = 1;
		const DBVERSION = 1;
		public function WPMUAutomaticLinks(){
			$this->plugin_name = plugin_basename(__FILE__);

			if ( is_admin() ) {
				// Start this plugin once all other plugins are fully loaded
				add_action( 'plugins_loaded', array(&$this, 'start_plugin') );
				add_action('admin_menu',array (&$this, 'add_admin_menu'));
			}
			add_filter('the_content', array(&$this,'filterContent'),100);
			register_activation_hook( $this->plugin_name, array(&$this,'activate'));
			register_uninstall_hook( $this->plugin_name, array('WPMUAutomaticLinks', 'uninstall') );
		}
		public function start_plugin(){
			load_plugin_textdomain(self::DOMAIN, false, dirname($this->plugin_name).'/languages');
		}
		public static function getUrl(){
			return $PHP_SELF.'?page='.self::DOMAIN;
		}
		public static function uninstall(){
			require_once(ABSPATH . 'wp-admin/upgrade-functions.php');
			if ( is_multisite() ) {
				$current_blog = $wpdb->blogid;
				// Get all blog ids
				$blogids = $wpdb->get_col($wpdb->prepare("SELECT blog_id FROM $wpdb->blogs"));
				foreach ($blogids as $blog_id) {
					switch_to_blog($blog_id);
					self::dropDatabase();
				}
				switch_to_blog($current_blog);
				return;
			}
			self::dropDatabase();
		}
		public static function dropDatabase() {
			global $wpdb;
			$table_name= $wpdb->prefix.self::DOMAIN;
			$sql = "DROP TABLE $table_name;";
			$wpdb->query($sql);
		}
		public static function activate(){
			global $wpdb;

			if ( is_multisite() ) {
				// check if it is a network activation - if so, run the activation function for each blog id
				if (isset($_GET['networkwide']) && ($_GET['networkwide'] == 1)) {
					$current_blog = $wpdb->blogid;
					// Get all blog ids
					$blogids = $wpdb->get_col($wpdb->prepare("SELECT blog_id FROM $wpdb->blogs"));
					foreach ($blogids as $blog_id) {
						switch_to_blog($blog_id);
						self::install();
					}
					switch_to_blog($current_blog);
					return;
				}
			}
			self::install();
		}
		public static function install(){
			global $wpdb;

			require_once(ABSPATH . 'wp-admin/upgrade-functions.php');

			$table_name= $wpdb->prefix.WPMUAutomaticLinks::DOMAIN;
			if( !$wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) ) {
				$sql = " CREATE TABLE $table_name(
					id mediumint(9) NOT NULL AUTO_INCREMENT ,
					text varchar(255) NOT NULL ,
					url varchar(255) NOT NULL ,
					anchortext varchar(255) NOT NULL ,
					css varchar(255) NOT NULL ,
					rel tinyint(1) NOT NULL ,
					type tinyint(1) NOT NULL ,
					visits int(10) NOT NULL ,
					PRIMARY KEY ( id ),
					UNIQUE (text)
				) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci;";
				$wpdb->query($sql);
				update_option("WPMUAutomaticLinks_db_version", self::DBVERSION);
			}else{
				//update db
				$dbVersion=self::getDbVersion();
				if($dbVersion != self::DBVERSION){
					self::updateDb($dbVersion,self::DBVERSION);
				}
			}

		}
		public static function checkAndUpdateDb(){
			$dbVersion=self::getDbVersion();
			if($dbVersion != self::DBVERSION){
				self::activate();
			}
		}
		public static function getDbVersion(){
			$dbVersion=get_option('WPMUAutomaticLinks_db_version');
			if(empty($dbVersion)){
				$dbVersion=0;
				add_option("WPMUAutomaticLinks_db_version", $dbVersion);
			}
			return $dbVersion;
		}
		public static function updateDb($from,$to){
			global $wpdb;
			$table_name= $wpdb->prefix.WPMUAutomaticLinks::DOMAIN;
			$sql=array(
				/*v0 to v1*/"ALTER TABLE $table_name ADD css varchar(255);"
			);
			for($i=$from;$i<$to;$i++){
				$wpdb->query($sql[$i]);
				update_option("WPMUAutomaticLinks_db_version", $i+1);
			}
		}
		function getLinks($orderBy="id",$order="desc", $master=false){
			global $wpdb;
			$current_blog = $wpdb->blogid;
			if($master && $current_blog != self::MASTERBLOG) {
				switch_to_blog(self::MASTERBLOG);
			}else if($master && $current_blog == self::MASTERBLOG) {
				return array();
			}

			$table_name= $wpdb->prefix.self::DOMAIN;
			$query = "select * from $table_name order by ".$orderBy." ".$order;
			$links = $wpdb->get_results($query);
			if($master) {
				restore_current_blog();
			}
			return $links;
		}
		function getLink($id){
			global $wpdb;
			$table_name= $wpdb->prefix.self::DOMAIN;
			$query = "select * from $table_name where id=$id";
			return $wpdb->get_row($query);
		}
		public function add_admin_menu(){
			self::checkAndUpdateDb();
			if (function_exists('add_options_page')) {
				add_options_page(__('WPMU Automatic Links options',self::DOMAIN), __('WPMU Automatic Links options',self::DOMAIN), self::ADMINISTRATOR, self::DOMAIN, array (&$this, 'show_admin_menu'));
			}
		}
		public function filterContent($content=''){
			self::checkAndUpdateDb();

			/*$exclude = array();*/

			if ( is_multisite() ) {
				switch_to_blog(self::MASTERBLOG);
				$content = $this->changeContent($content/*, &$exclude*/);
				restore_current_blog();
			}
			$content = $this->changeContent($content/*, &$exclude*/);
			return $content;
		}
		public function changeContent($content = ''/*, $exclude = array()*/){
			global $wpdb;
			$table_name= $wpdb->prefix.self::DOMAIN;

			$links = $wpdb->get_results("select * from $table_name");

			//prepare the content
			$mark = "!!!WPMUAL---CUTHERE!!!";

			$content = str_replace(array("<",">"),array($mark."<",">".$mark),$content);
			$content = explode($mark, $content);

			/*
			 * Replacement
			 */
			foreach($links as $link) {
				$link->type = $this->getTarget($link->type);
				$link->rel = $this->getRel($link->rel);

				$replacement = '<a href="'.$link->url.'"';

				if (!empty($link->css))
					$replacement .= ' class="'.$link->css.'"';
				if (!empty($link->type))
					$replacement .= ' target="'.$link->type.'"';
				if (!empty($link->rel))
					$replacement .= ' rel="'.$link->rel.'"';

				$replacement .=	' title="'.$link->anchortext.'" >';

				$content = preg_replace("/([\ ]*)(" . $link->text . ")([\ \,\.])/", "$1" . $replacement . $link->text . "</a>$3", $content);
			}

			$content = implode("",$content);
			return $content;
		}
		public function getTarget($target = 0){
			switch($target){
				case 0: return ""; break;
				case 1: return "_self"; break;
				case 2: return "_top"; break;
				case 3: return "_blank"; break;
				case 4: return "_parent"; break;
			}
		}
		public function getRel($rel = 0){
			switch($rel){
				case 0: return ""; break;
				case 1: return "external"; break;
				case 2: return "nofollow"; break;
			}
		}
		function updateLinkCount($id){
			if ( is_admin() ) {
				return true;
			}
			global $wpdb;
			$table_name= $wpdb->prefix.self::DOMAIN;

			$query = "update $table_name set `visits` = `visits`+1 where id=$id";
			$wpdb->query($query);
			return true;
		}
		public function updateLink($id,$url,$text,$anchorText,$css,$rel,$type){
			global $wpdb;
			if(get_magic_quotes_gpc()) {
				$text = stripslashes($text);
				$css = stripslashes($css);
				$anchorText = stripslashes($anchorText);
			}
			$table_name= $wpdb->prefix.self::DOMAIN;
			return $wpdb->update($table_name, array('url' => mysql_real_escape_string($url),
													'text' => mysql_real_escape_string($text),
													'anchortext' => mysql_real_escape_string($anchorText),
													'css' => mysql_real_escape_string($css),
													'rel' => $rel,
													'type' => $type
											), array('id' => $id),array('%s','%s','%s','%s','%d','%d'), '%d');

		}
		public function addLink($url,$text,$anchorText,$css,$rel,$type){
			global $wpdb;

			if(get_magic_quotes_gpc()) {
				$text = stripslashes($text);
				$css = stripslashes($css);
				$anchorText = stripslashes($anchorText);
			}
			if ( is_multisite() ) {
				switch_to_blog(self::MASTERBLOG);
				$table_name= $wpdb->prefix.self::DOMAIN;
				restore_current_blog();
				if($wpdb->get_var( 'select id from $table_name where text ="'.mysql_real_escape_string($text).'"')) {
					return false;
				}
			}
			$table_name= $wpdb->prefix.self::DOMAIN;

			return $wpdb->insert($table_name, array('url' => mysql_real_escape_string($url),
													'text' => mysql_real_escape_string($text),
													'anchortext' => mysql_real_escape_string($anchorText),
													'css' => mysql_real_escape_string($css),
													'rel' => $rel,
													'type' => $type,
													'visits' => 0,
											), array('%s','%s','%s','%s','%d','%d','%d'));
		}
		public function deleteLink($id){
			global $wpdb;
			require_once(ABSPATH . 'wp-admin/upgrade-functions.php');

			$table_name= $wpdb->prefix.self::DOMAIN;
			$sql = "DELETE FROM $table_name where id = $id;";
			$wpdb->query($sql);
		}
		public function show_admin_menu(){
			global $wpdb;
			isset($_GET['acc']) ? $_acc=$_GET['acc']:$_acc="showLinks";

			if(!empty($_POST['url'])){
				if(!empty($_POST['id'])){
					if($this->updateLink($_POST['id'],$_POST['url'],$_POST['text'],$_POST['alt'],$_POST['css'],$_POST['rel'],$_POST['type']))
						$this->showMessage('updated', __('Link correctly updated!',WPMUAutomaticLinks::DOMAIN));
					$_acc="showLinks";
				}else{
					if($this->addLink($_POST['url'],$_POST['text'],$_POST['alt'],$_POST['css'],$_POST['rel'],$_POST['type']))
						$this->showMessage('updated', __('Link correctly added!',WPMUAutomaticLinks::DOMAIN));
					else
						$this->showMessage('error', __('ERROR! This word is in database!',WPMUAutomaticLinks::DOMAIN));
					$_acc="addLink";
				}
			}else{
				if($_GET['acc']=="del") {
					if(!empty($_GET['id'])){
						$this->deleteLink($_GET['id']);
						$this->showMessage('updated', __('Link correctly deleted!',WPMUAutomaticLinks::DOMAIN));
					}
					$_acc="showLinks";
				}
			}

			?>
			<link rel="stylesheet" type="text/css" href="<?php echo WP_PLUGIN_URL.'/'.str_replace(basename( __FILE__),"",plugin_basename(__FILE__)).'style.css';?>" />
			<script type="text/javascript">
			function deleteLink(id){
				var opc = confirm("<?php _e('You are going to delete this link, are you sure?',WPMUAutomaticLinks::DOMAIN); ?>");
				if (opc==true)
					window.location.href="<?php echo WPMUAutomaticLinks::getUrl(); ?>&acc=del&id="+id;
			}
			</script>
			<div class="wrap">
				<h2><?php _e('WPMU Automatic Links',WPMUAutomaticLinks::DOMAIN); ?></h2>

				<ul class="subsubsub">
					<li><a href="<?php echo WPMUAutomaticLinks::getUrl(); ?>&acc=showLinks"><?php _e('Links',WPMUAutomaticLinks::DOMAIN); ?></a> |</li>
					<li><a href="<?php echo WPMUAutomaticLinks::getUrl(); ?>&acc=addLink"><?php _e('Add links',WPMUAutomaticLinks::DOMAIN); ?></a></li>
				</ul>
			<?php
				switch ($_acc){
					case "addLink":
						$this->showForm();
						break;
					case "edit":
						$id = $_GET['id'];
						$link = $this->getLink($id);
						$this->showForm($link->id,$link->text,$link->url,$link->anchortext,$link->css,$link->type,$link->rel);
						break;
					case "showLinks":
						if(empty($_GET['orderBy']))
							$_GET['orderBy'] = "id";
						if(empty($_GET['order']))
							$_GET['order'] = "desc";
						$links = $this->getLinks($_GET['orderBy'], $_GET['order']);

						$exclude = array();
						$current_blog = $wpdb->blogid;
						if (is_multisite() && $current_blog != self::MASTERBLOG) {
							$masterLinks = $this->getLinks($_GET['orderBy'], $_GET['order'], true);
							$this->showLinks($masterLinks, false, __('Links on master blog',WPMUAutomaticLinks::DOMAIN), $exclude);
						}
						$this->showLinks($links, true, __('Links',WPMUAutomaticLinks::DOMAIN), $exclude);


						if(is_multisite() && $current_blog == self::MASTERBLOG) {
							$blogids = $wpdb->get_col($wpdb->prepare("SELECT blog_id FROM $wpdb->blogs"));
							foreach ($blogids as $blog_id) {
								if($blog_id == $current_blog){
									continue;
								}
								switch_to_blog($blog_id);
								$links = $this->getLinks($_GET['orderBy'], $_GET['order'], false);
								$this->showLinks($links, false, __('Links on ',WPMUAutomaticLinks::DOMAIN).get_bloginfo('name'), $exclude);
							}
							switch_to_blog($current_blog);
						}
						break;
				}
			?>
			</div>
			<?php
		}
		public function showLinks($links, $editable = false, $title, &$exclude = array()){?>
			<h3><?php echo $title; ?></h3>
			<table class="widefat">
				<thead>
					<tr>
						<th scope="col">
							<?php _e('Word :',WPMUAutomaticLinks::DOMAIN); ?> (<a href="<?php echo WPMUAutomaticLinks::getUrl();?>&acc=showLinks&orderBy=text&order=asc">+</a>|<a href="<?php echo WPMUAutomaticLinks::getUrl();?>&acc=showLinks&orderBy=text&order=desc">-</a>)
						</th>
						<th scope="col">
							<?php _e('Url :',WPMUAutomaticLinks::DOMAIN); ?> (<a href="<?php echo WPMUAutomaticLinks::getUrl();?>&acc=showLinks&orderBy=url&order=asc">+</a>|<a href="<?php echo WPMUAutomaticLinks::getUrl();?>&acc=showLinks&orderBy=url&order=desc">-</a>)
						</th>
						<th scope="col">
							<?php _e('Title :',WPMUAutomaticLinks::DOMAIN); ?> (<a href="<?php echo WPMUAutomaticLinks::getUrl();?>&acc=showLinks&orderBy=anchortext&order=asc">+</a>|<a href="<?php echo WPMUAutomaticLinks::getUrl();?>&acc=showLinks&orderBy=anchortext&order=desc">-</a>)
						</th>
						<th scope="col">
							<?php _e('Rel :',WPMUAutomaticLinks::DOMAIN); ?> (<a href="<?php echo WPMUAutomaticLinks::getUrl();?>&acc=showLinks&orderBy=rel&order=asc">+</a>|<a href="<?php echo WPMUAutomaticLinks::getUrl();?>&acc=showLinks&orderBy=rel&order=desc">-</a>)
						</th>
						<th scope="col">
							<?php _e('Target :',WPMUAutomaticLinks::DOMAIN); ?> (<a href="<?php echo WPMUAutomaticLinks::getUrl();?>&acc=showLinks&orderBy=type&order=asc">+</a>|<a href="<?php echo WPMUAutomaticLinks::getUrl();?>&acc=showLinks&orderBy=type&order=desc">-</a>)
						</th>
						<th scope="col"><?php _e('CSS',WPMUAutomaticLinks::DOMAIN); ?></th>
						<th scope="col"><?php _e('Visits',WPMUAutomaticLinks::DOMAIN); ?></th>
						<th scope="col"><?php _e('Delete',WPMUAutomaticLinks::DOMAIN); ?></th>
						<th scope="col"><?php _e('Edit',WPMUAutomaticLinks::DOMAIN); ?></th>
					</tr>
				</thead>
				<tbody id="the-comment-list" class="list:comment">
					<?php foreach($links as $link){ ?>
						<tr id="link-<?php echo $link->id; ?>" class="<?php if(in_array($link->text,$exclude)) {echo "disabled";}?>">
							<td><?php echo stripslashes($link->text); ?></td>
							<td><?php echo $link->url; ?></td>
							<td><?php echo stripslashes($link->anchortext); ?></td>
							<td><?php echo $this->getRel($link->rel); ?></td>
							<td><?php echo $this->getTarget($link->type); ?></td>
							<td><?php echo $link->css; ?></td>
							<td><?php echo $link->visits; ?></td>
							<td>
								<?php if($editable){?>
								<a href="javascript:deleteLink(<?php echo $link->id; ?>);"><?php _e('Delete',WPMUAutomaticLinks::DOMAIN); ?></a>
								<?php } ?>
							</td>
							<td>
								<?php if($editable){?>
								<a href="<?php echo WPMUAutomaticLinks::getUrl();?>&acc=edit&id=<?php echo $link->id;?>"><?php _e('Edit',WPMUAutomaticLinks::DOMAIN); ?></a>
								<?php } ?>
							</td>
						</tr>
					<?php
							$exclude[] = $link->text;
						} ?>
				</tbody>
			</table>
		<?php
		}
		public function showMessage($class, $text){
			?>
			<div id="message" class="<? echo $class; ?> fade">
				<strong><?php echo $text; ?></strong>
			</div>
			<?php
		}
		public function showForm($id='', $text='', $url='' ,$anchortext= '',$css='', $type='', $rel=''){
		?>
			<h3><?php if (empty($id)) { _e('New link',WPMUAutomaticLinks::DOMAIN); } else { _e('Edit link',WPMUAutomaticLinks::DOMAIN); } ?></h3>
			<form method="post" action ="">
				<input type="hidden" name="id" value="<?php echo $id; ?>"/>
				<fieldset>
					<legend><?php _e('Parameters :',WPMUAutomaticLinks::DOMAIN); ?></legend>
					<div>
					<label for="<?php echo WPMUAutomaticLinks::DOMAIN; ?>Text"><?php _e('Word :',WPMUAutomaticLinks::DOMAIN); ?></label>
					<input maxlength="255"  id="<?php echo WPMUAutomaticLinks::DOMAIN; ?>Text" type="text" name="text" value="<?php esc_attr_e(stripslashes($text)); ?>"/>
					</div><div>
					<label for="<?php echo WPMUAutomaticLinks::DOMAIN; ?>Url"><?php _e('Url :',WPMUAutomaticLinks::DOMAIN); ?></label>
					<input maxlength="255" id="<?php echo WPMUAutomaticLinks::DOMAIN; ?>Url" type="text" name="url" value="<?php echo $url; ?>" />
					</div><div>
					<label for="<?php echo WPMUAutomaticLinks::DOMAIN; ?>Title"><?php _e('Title :',WPMUAutomaticLinks::DOMAIN); ?></label>
					<input maxlength="255"  id="<?php echo WPMUAutomaticLinks::DOMAIN; ?>Title" type="text" name="alt" value="<?php esc_attr_e(stripslashes($anchortext)); ?>"/>
					</div><div>
					<label for="<?php echo WPMUAutomaticLinks::DOMAIN; ?>CSS"><?php _e('CSS :',WPMUAutomaticLinks::DOMAIN); ?></label>
					<input maxlength="255"  id="<?php echo WPMUAutomaticLinks::DOMAIN; ?>CSS" type="text" name="css" value="<?php esc_attr_e(stripslashes($css)); ?>"/>
					</div><div>
					<label for="<?php echo WPMUAutomaticLinks::DOMAIN; ?>Target"><?php _e('Target :',WPMUAutomaticLinks::DOMAIN); ?></label>
					<select id="<?php echo WPMUAutomaticLinks::DOMAIN; ?>Target" name="type">
						<option value="0" <?php if($type == 0) echo 'selected="selected"'; ?>><?php echo $this->getTarget(0); ?></option>
						<option value="1" <?php if($type == 1) echo 'selected="selected"'; ?>><?php echo $this->getTarget(1); ?></option>
						<option value="2" <?php if($type == 2) echo 'selected="selected"'; ?>><?php echo $this->getTarget(2); ?></option>
						<option value="3" <?php if($type == 3) echo 'selected="selected"'; ?>><?php echo $this->getTarget(3); ?></option>
						<option value="4" <?php if($type == 4) echo 'selected="selected"'; ?>><?php echo $this->getTarget(4); ?></option>
					</select>
					</div><div>
					<label for="<?php echo WPMUAutomaticLinks::DOMAIN; ?>Rel"><?php _e('Rel :',WPMUAutomaticLinks::DOMAIN); ?></label>
					<select id="<?php echo WPMUAutomaticLinks::DOMAIN; ?>Rel" name="rel">
						<option value="0" <?php if($rel == 0) echo 'selected="selected"'; ?>><?php echo $this->getRel(0); ?></option>
						<option value="1" <?php if($rel == 1) echo 'selected="selected"'; ?>><?php echo $this->getRel(1); ?></option>
						<option value="2" <?php if($rel == 2) echo 'selected="selected"'; ?>><?php echo $this->getRel(2); ?></option>
					</select>
					</div>
					<input type="submit" name="submit" value="<?php if (empty($id)) {_e('Add',WPMUAutomaticLinks::DOMAIN);}else{_e('Update',WPMUAutomaticLinks::DOMAIN);} ?>" /></p>
				</fieldset>
			</form>
		<?php
		}
	}
	$_WPMUAutomaticLinks = new WPMUAutomaticLinks();
}
?>