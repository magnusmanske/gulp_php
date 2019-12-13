<?PHP

require_once ( '/data/project/mix-n-match/public_html/php/ToolforgeCommon.php' ) ;
#require_once ( '/data/project/mix-n-match/public_html/php/wikidata.php' ) ;
#require_once ( '/data/project/quickstatements/public_html/quickstatements.php' ) ;

class GULP {
	public $tfc ;

	private $db_gulp ;
	private $last_error ;
	private $allowed_column_value_types ;
	private $allowed_rc_actions ;
	private $actions ;

	function __construct() {
		$this->tfc = new ToolforgeCommon('gulp') ;
		$this->tfc->use_db_cache = false ;
		$this->reset_error() ;
	}

	public function ok() {
		return $this->last_error == '' ;
	}

	public function get_error_message() {
		return $this->last_error ;
	}

	public function reset_error() {
		$this->last_error = '' ;
	}

	public function dbDisconnect() {
		$this->dbConnect(false);
	}

	public function getOrCreateUserID ( string $username ) {
		$this->dbConnect();
		$username = $this->normalizeUsername ( $username ) ;
		if ( $username == '' ) return $this->error('Blank user name');
		$sql = "SELECT * FROM `user` WHERE `name`='".$this->escape($username)."'" ;
		$result = $this->getSQL ( $sql ) ;
		while($o = $result->fetch_object()) return $o->id ;
		$sql = "INSERT IGNORE INTO `user` (`name`) VALUES ('".$this->escape($username)."')" ;
		if ( !$this->getSQL ( $sql ) ) return $this->error($this->db_gulp->error);
		return $this->getOrCreateUserID ( $username ) ;
	}

	public function createNewList ( string $name , int $user_id ) {
		$user_id *= 1 ;
		$name = trim($name) ;
		if ( $user_id == 0 ) return $this->error('Invalid user ID');
		if ( $name == '' ) return $this->error('Blank list name');
		$sql = "INSERT INTO `list` (`name`) VALUES ('" . $this->escape($name) . "')" ;
		if ( !$this->getSQL ( $sql ) ) return $this->error($this->db_gulp->error);
		$list_id = $this->db_gulp->insert_id ;
		if ( !$this->rc ( $user_id , $list_id , 0 , 'CREATE_LIST' ) ) return ;
		$revision_id = $this->createNewRevision ( $list_id , $user_id ) ;
		if ( !$revision_id ) return FALSE ;
		return [$list_id,$revision_id] ;
	}

	public function createNewRevision ( int $list_id , int $user_id ) {
		$user_id *= 1 ;
		$list_id *= 1 ;
		if ( $user_id == 0 ) return $this->error('Invalid user ID');
		if ( $list_id == 0 ) return $this->error('Invalid list ID');
		$sql = "INSERT INTO `revision` (`list_id`) VALUES ({$list_id})" ;
		if ( !$this->getSQL ( $sql ) ) return $this->error($this->db_gulp->error);
		$revision_id = $this->db_gulp->insert_id ;
		if ( !$this->rc ( $user_id , $list_id , $revision_id , 'CREATE_REVISION' , ['revision_id'=>$revision_id] ) ) return ;
		return $revision_id ;
	}

	public function getCurrentRevison ( int $list_id ) {
		$list_id *= 1 ;
		if ( $list_id == 0 ) return $this->error('Invalid list ID');
		$sql = "SELECT max(`id`) AS cur_rev_id FROM `revision` WHERE `list_id`={$list_id}" ;
		if ( !$this->getSQL ( $sql ) ) return $this->error($this->db_gulp->error);
		while($o = $result->fetch_object()) return $o->cur_rev_id ;
		return $this->error("No revision for list {$list_id}");
	}

	public function getListForRevision ( int $revision_id ) {
		$list_id *= 1 ;
		if ( $list_id == 0 ) return $this->error('Invalid list ID');
		$sql = "SELECT `list_id` FROM `revision` WHERE `id`={$revision_id}" ;
		$result = $this->getSQL ( $sql ) ;
		if ( !$result ) return $this->error($this->db_gulp->error);
		while($o = $result->fetch_object()) return $o->list_id ;
		return $this->error("No such revision {$revision_id}");
	}

	public function setColumns ( int $revision_id , array $columns , int $user_id ) {
		$revision_id *= 1 ;
		if ( $revision_id == 0 ) return $this->error('Invalid revision ID');
		$list_id = $this->getListForRevision ( $revision_id ) ;
		if ( $list_id === FALSE ) return ;

		foreach ( $columns AS $number => $column ) {
			if ( !is_array ( $column ) ) return $this->error ( "Bad type for column {$number} list {$list_id} revision {$revision_id}" ) ;
			if ( length($column) != 2 ) return $this->error ( "Bad length for column {$number} list {$list_id} revision {$revision_id}" ) ;
			if ( FALSE === $this->setColumn ( $list_id , $revision_id , $number , $column[0] , $column[1] ) ) return ;
		}

		return $this->rc ( $user_id , $list_id , $revision_id , 'SET_COLUMNS' , $columns ) ;
	}

	# PRIVATE

	private function setColumn ( int $list_id , int $revision_id , int $number , string $label , string $value_type ) {
		# Paranoia
		$this->loadAllowedColumnTypes() ;
		$number *= 1 ;
		$list_id *= 1 ;
		$revision_id *= 1 ;
		$label = trim ( $label ) ;
		if ( $list_id == 0 ) return $this->error('Invalid list ID');
		if ( $revision_id == 0 ) return $this->error('Invalid revision ID');
		if ( !in_array ( $value_type , $this->allowed_column_value_types) ) return $this->error ( "Not an allowed value type: {$value_type}" ) ;
		if ( $label == '' ) return $this->error ( "Blank column label" ) ;
		if ( $number < 0 ) return $this->error ( "Invalid column number {$number}" ) ;

		# Find existing column
		$column_id_to_close = 0 ;
		$value_type_change = false ;
		$sql = "SELECT * FROM `column` WHERE `rev_end` IS NULL AND `list_id`={$list_id} AND `number`={$number}" ;
		$result = $this->getSQL ( $sql ) ;
		if ( !$result ) return $this->error($this->db_gulp->error);
		while($o = $result->fetch_object()) {
			if ( $column_id_to_close != 0 ) return $this->error ( "Multiple columns for number {$number} list {$list_id} revision {$revision_id}") ;
			if ( $o->label == $label and $o->value_type == $value_type ) return FALSE ; # Found one exactly like it, keep rev_end open
			$value_type_change = $o->value_type != $value_type ;
			$column_id_to_close = $o->id ;
		}
		
		# Close existing column
		if ( $column_id_to_close > 0 ) {
			$previous_revision_id = $this->getPreviousRevision ( $list_id , $revision_id ) ;
			$sql = "UPDATE `column` SET `rev_end`={$previous_revision_id} WHERE `id`={$column_id_to_close} AND `rev_end` IS NULL" ;
			$result = $this->getSQL ( $sql ) ;
			if ( !$result ) return $this->error($this->db_gulp->error);
			if ( $value_type_change ) {
				$this->closeCellsForColumn ( $column_id_to_close , $previous_revision_id ) ;
			}
		}

		# Insert new column
		$label = $this->escape ( $label ) ;
		$sql = "INSERT INTO `column` (`list_id`,`number`,`label`,`value_type`,`rev_start`,`rev_end`) VALUES ({$list_id},{$number},'{$label}','{$value_type}',{$revision_id},null)" ;
		print "{$sql}\n" ;
		$result = $this->getSQL ( $sql ) ;
		if ( !$result ) return $this->error($this->db_gulp->error);
		$column_id = $this->db_gulp->insert_id ;
		if ( !$value_type_change ) {
			$this->changeColumnForCells ( $column_id_to_close , $column_id ) ;
		}
		return $column_id ;
	}

	private function changeColumnForCells ( int $old_column_id , int $new_column_id ) {
		$old_column_id *= 1 ;
		$new_column_id *= 1 ;
		if ( $old_column_id == 0 ) return $this->error('Invalid old_column ID');
		if ( $new_column_id == 0 ) return $this->error('Invalid new_column ID');
		$this->loadAllowedColumnTypes() ;
		foreach ( $this->allowed_column_value_types AS $column_type ) {
			$sql = "UPDATE `cell_{$column_type}` SET `column_id`={$new_column_id} WHERE `column_id`={$old_column_id}" ;
			$result = $this->getSQL ( $sql ) ;
			if ( !$result ) return $this->error($this->db_gulp->error);
		}
	}

	private function closeCellsForColumn ( int $column_id , int $previous_revision_id ) {
		$column_id *= 1 ;
		$previous_revision_id *= 1 ;
		if ( $column_id == 0 ) return $this->error('Invalid column ID');
		if ( $previous_revision_id == 0 ) return $this->error('Invalid previous_revision ID');
		$this->loadAllowedColumnTypes() ;
		foreach ( $this->allowed_column_value_types AS $column_type ) {
			$sql = "UPDATE `cell_{$column_type}` SET `rev_end`={$previous_revision_id} WHERE `rev_end` IS NULL AND `column_id`={$column_id}" ;
			$result = $this->getSQL ( $sql ) ;
			if ( !$result ) return $this->error($this->db_gulp->error);
		}
	}

	private function loadAllowedColumnTypes() {
		return loadAllowedSetTypes ( 'column' , 'value_type' ) ;
	}

	private function loadAllowedRcActions() {
		return loadAllowedSetTypes ( 'rc' , 'action' ) ;
	}

	private function loadAllowedSetTypes ( $table , $set ) {
		$varname = "allowed_{$table}_{$set}s" ;
		if ( isset($this->$varname) ) return ;
		$sql = "DESCRIBE `{$table}` `{$set}`" ;
		$result = $this->getSQL ( $sql ) ;
		if ( !$result ) return $this->error("[A] {$table}/{$set}: ",$this->db_gulp->error);
		$o = $result->fetch_object() ;
		$fields = $o->Type ;
		if ( FALSE === preg_match_all ( "/'(.+?)'/" , $fields , $m ) ) return $this->error("[B]{$table}/{$set}: ",$this->db_gulp->error);
		$this->$varname = $m[1] ;
	}

	private function getPreviousRevision ( int $list_id , int $revision_id ) {
		$list_id *= 1 ;
		$revision_id *= 1 ;
		if ( $list_id == 0 ) return $this->error('Invalid list ID');
		if ( $revision_id == 0 ) return $this->error('Invalid revision ID');
		$sql = "SELECT max(`id`) AS cur_rev_id FROM `revision` WHERE `list_id`={$list_id} AND `id`<{$revision_id}" ;
		if ( !$this->getSQL ( $sql ) ) return $this->error($this->db_gulp->error);
		while($o = $result->fetch_object()) return $o->cur_rev_id ;
		return $this->error("No revision before {$revision_id} for list {$list_id}");
	}

	private function error ( string $msg ) {
		$calling_function = debug_backtrace()[1]['function'];
		$this->last_error = "{$calling_function}: {$msg}" ;
		return FALSE;
	}

	private function getCurrentTimestamp () {
		return date ( 'YmdHis' ) ;
	}

	private function dbConnect(bool $create_connection=true) {
		if ( $create_connection ) {
			if ( isset ( $this->db_gulp ) ) return ;
			$this->db_gulp = $this->openGulpDB() ;
		} else {
			unset ( $this->db_gulp ) ;
		}
	}

	private function openGulpDB() {
		$db = $this->tfc->openDBtool ( 'gulp' ) ;
		if ( $db === false ) die ( "Cannot access GULP DB" ) ;
		return $db ;
	}

	private function escape ( string $s ) {
		$this->dbConnect();
		return $this->db_gulp->real_escape_string ( $s ) ;
	}

	private function getSQL ( string $sql ) {
		$this->dbConnect() ;
		$ret = $this->tfc->getSQL ( $this->db_gulp , $sql ) ;
		if ( !isset($ret) ) return FALSE ;
		return $ret ;
	}

	private function normalizeUsername ( string $username ) {
		return ucfirst ( trim ( str_replace ( '_' , ' ' , $username ) ) ) ;
	}

	private function rc ( int $user_id , int $list_id , int $revision_id , string $action , $json = [] ) {
		$this->loadAllowedRcActions();
		$user_id *= 1 ;
		$list_id *= 1 ;
		$revision_id *= 1 ; # 0 is valid!
		if ( $user_id == 0 ) return $this->error('Invalid user ID');
		if ( $list_id == 0 ) return $this->error('Invalid list ID');
		if ( !in_array($action,$this->allowed_rc_actions) ) return $this->error("Invalid action '{$action}'");
		$json = json_encode ( $json ) ;
		if ( $json == '[]' ) $json = 'null' ;
		else $json = '"' . $this->escape($json) . '"' ;
		if ( $revision_id == 0 ) $revision_id = 'null' ;
		$action = $this->escape ( $action ) ;
		$ts = $this->getCurrentTimestamp() ;
		$sql = "INSERT INTO `rc` (`user_id`,`list_id`,`revision_id`,`action`,`json`,`timestamp`) VALUES ({$user_id},{$list_id},{$revision_id},'{$action}',{$json},'{$ts}')" ;
		return $this->getSQL ( $sql ) ;
	}
}

?>