<?php
/**
 * PDO基类
 * User: chenxiong<cloklo@qq.com>
 * Date: 13-9-15
 */

class CxPdo extends PDO{
	

	private static $_pdo = array();
	 
	function __construct($driver,$option=null){
		$dsn = "{$driver['DB_DRIVER']}:host={$driver['DB_HOST']};port={$driver['DB_PORT']};dbname={$driver['DB_NAME']}";
		parent::__construct($dsn, $driver['DB_USER'], $driver['DB_PASS'], $option);
	}
	
	/**
	 * 根据参数$option初始化PDO
	 * @var CxPdo
	 */
	public static function Init($driver,$option=null){
		$key = $driver['DB_HOST'].$driver['DB_PORT'].$driver['DB_NAME'];
		if(!isset(self::$_pdo[$key])) {
			self::$_pdo[$key] = new CxPdo($option);
		}
		return self::$_pdo[$key];
	}

	/**
	 * 根据参数$option初始化PDO
	 * @var CxPdo
	 */
	public static function getInstance($option = NULL){
		$key = $option['DB_HOST'].$option['DB_PORT'].$option['DB_NAME'];
		if(!isset(self::$_pdo[$key])) {
			$options = array(
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_PERSISTENT => $option['DB_CONNECT'],#pdo默认为false
				PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES '.$option['DB_CHARSET']
			);
			self::$_pdo[$key] = new CxPdo($option,$options);
		}
		return self::$_pdo[$key];
	}
	
	
	/**
	 * 切换数据库
	 * @param string $db
	 */
	public function changeDb($db) {
		return $this->exec('USE '.$db);
	}
	
	
	/**
	 * @return PDOStatement
	 */
	public function execute($sql, $params=NULL,$flag=true) {
		if(DEBUG){ CxBug::sql($sql, $params); }
		$db=$this->prepare($sql);
		$result =   is_null($params) ? $db->execute() : $db->execute($params);
		if ( false !== $result ) {
			return $db;
		}
		if(DEBUG){
		   $result = $db->errorInfo();
		   if(empty($result)) $result = 'sql execute error!';
		   else $result = $result[2];
		   throw new Exception($result);
		}
	}
	/**
	 *
	 * @param string $name 存储过程的名字
	 * @param string|array $in 输入参数
	 * @param string $out 输出参数
	 * @return Ambigous <NULL, array>
	 */
	public function call($name,$in = null,$out = null){
		//$pdo = self::$pdo;
		$sql = 'CALL ' . $name . '(';
		if($in != null){
			if(is_array($in)){
				$comma = '';
				foreach ($in as $v){
					$sql .= $comma.'?'; $comma = ',';
				}
			}
			else {
				$sql .= $in.','; $in = null;
			}
		}
		if($out != null){
			if(!empty($in)) $sql .= ','; $sql .= $out;
		}
		$sql .= ')';
		$row = $this -> execute($sql,$in);
		$data = null;
		do{
			$result = $row -> fetchAll();
			if($result != null) {
				$data['table'][] = $result;
			}
		}
		while ($row -> nextRowset());
		if($out != null){
			$data['out'] = $this -> query('select ' . $out) -> fetch();
		}
		return $data;
	}
}


/**
 * 对CxDao的补充，负责构建各种SQL语句
 * User: chenxiong<cloklo@qq.com>
 * Date: 13-9-15
 */

class CxTable {
	/**
	 * @var CxPdo
	 */
	private $db;

	/** @var String  table name */
	private $tableName = '';

	/** @var String  current table's alias, default is table name without prefix */
	private $tableAlias = '';

	/** @var String  fields part of the select clause, default is '*' */
	private $fields = '*';

	/** @var String  Join clause */
	private $join = '';

	/** @var String  condition*/
	private $where = '';

	/** @var String  condition*/
	private $having = '';

	/** @var Array  params used to replace the placehold in condition*/
	private $params = NULL;

	/** @var String  e.g. Id ASC */
	private $order = '';

	/** @var String  group by */
	private $group = '';

	/** @var current sql clause */
	private $sql = '';

	/** @var sql clause directly assigned by User */
	private $userSql = '';

	private $distinct = false;

	/** @var limit rows, start */
	private $limit = '';

	/** @var whether repared */
	//	private $prepared = false;
	/** @var whether repared */
	//	private $preparedSql = '';

	/*=== CONSTS ===*/
	/** @var String left join */
	const LEFT_JOIN = 'LEFT JOIN';
	/** @var String left join */
	const INNER_JOIN = 'INNER JOIN';
	/** @var String left join */
	const RIGHT_JOIN = 'RIGHT JOIN';

	function __construct($dbObj, $tableName, $tableAlias='') {
		$this->db = $dbObj;
		$this->tableName = $tableName;
		$this->tableAlias = $tableAlias ? $tableAlias : $tableName;
	}

	function setTableAlias($tableAlias) {
		$this->tableAlias = $tableAlias;
		return $this;
	}

	function sql($sql='', $params=NULL) {
		if (!empty($sql)) {
			$this->sql = '';
			$this->userSql = $sql;
			$this->params = $this->autoarr($params);
			return $this;
		} else {
			return $this->sql;
		}
	}

	function setField($fieldName) {
		if ($fieldName) {
			if ($this->fields && $this->fields != '*') {
				if($fieldName == 'SQL_CALC_FOUND_ROWS *'){
					$this->fields = 'SQL_CALC_FOUND_ROWS' ." $this->fields";
				}
				else if ($fieldName != '*') {
					$this->fields = $fieldName .",$this->fields";
				} 
				else {
					if (strpos($this->fields, $this->tableAlias.'.*') === false)
						$this->fields .= ','.$this->tableAlias.'.*';
				}
			} 
			else {
				$this->fields = $fieldName;
			}
		}
		return $this;
	}

	function field($fieldName) {
		return $this->setField($fieldName);
	}

	function distinct($distinct=false) {
		$this->distinct = $distinct;
		return $this;
	}


	private function addJoinField($fields) {
		if ($this->fields == '*') {
			$this->fields = "$this->tableAlias.*, $fields";
		} 
		else {
			$this->fields .= $this->fields ? ',' : '';
			$this->fields .= $fields;
		}
		return $this;
	}

	function join($table, $on='', $fields='', $jointype=CxTable::INNER_JOIN) {
		$as = $table;
		if (strchr($table, ' ')) {
			$tmp = explode(' ', str_replace(' as ', ' ', $table));
			$table = $tmp[0];
			$as = $tmp[1];
		}

		//$table = $this->db->prefix($table);

		if ($fields) $this->addJoinField($fields);

		$on = $on ? 'ON '.$on : '';

		$this->join .= " $jointype $table $as $on ";
		return $this;
	}

	public function leftJoin($table, $on='', $fields='') {
		return $this->join($table, $on, $fields, CxTable::LEFT_JOIN);
	}
	 
	function rightJoin($table, $on='', $fields='') {
		return $this->join($table, $on, $fields, CxTable::RIGHT_JOIN);
	}
	 
	function innerJoin($table, $on='', $fields='') {
		return $this->join($table, $on, $fields, CxTable::INNER_JOIN);
	}

	function where($condition, $params = NULL) {
		if ($condition) {
			$this->where = 'WHERE '.$condition;
			$this->params = $this->autoarr($params);
		}
		return $this;
	}

	function having($condition, $params = NULL) {
		$this->having = 'HAVING '.$condition;
		$this->params = empty($this->params) ?  $this->autoarr($params) : array_merge($this->params, $this->autoarr($params));
		return $this;
	}

	function orderby($order) {
		$this->order = $order;
		return $this;
	}

	function groupby($group) {
		$this->group = $group;
		return $this;
	}

	function limit($rows = 0, $start=0) {
		if ($rows === 0) {
			$this->limit = '';
		} else {
			$this->limit = "LIMIT $start,$rows";
		}
		return $this;
	}

	private function constructSql($return=true) {
		if (empty($this->userSql)) {
			$distinct = $this->distinct ? 'DISTINCT' : '';
				
			$groupby = '';
			if (!empty($this->group)) {
				$groupby = 'GROUP BY '.$this->group;
				if (!empty($this->having)) $groupby .= ' '.$this->having;
			}
			$order = !empty($this->order) ? "ORDER BY $this->order" : '';

			$sql = "SELECT $distinct $this->fields FROM `$this->tableName` `$this->tableAlias` $this->join $this->where $groupby $order $this->limit";
		} else {
			$sql = $this->userSql;
		}
		$this->reset();
		if ($return) {
			return $sql;
		} else {
			$this->sql = $sql;
		}
	}

	/**
	 * 执行一条SQL语句并返回一个statement对象
	 * @param Array/String $params
	 * @return PDOStatement query result
	 */
	function query($multi_call_params=NULL) {
		if (is_null($multi_call_params)) {
			return $this->db->execute($this->constructSql(), $this->params);
		} else {
			if (empty($this->sql)) $this->constructSql(false);
			return $this->db->execute($this->sql, $this->autoarr($multi_call_params));
		}
	}

	/**
	 * 获取一条结果
	 * PDO::FETCH_ASSOC：指定获取方式，将对应结果集中的每一行作为一个由列名索引的数组返回。
	 * 如果结果集中包含多个名称相同的列，则PDO::FETCH_ASSOC每个列名只返回一个值
	 * @param string $multi_call_params
	 * @param int $fetchMode
	 */
	function fetch($multi_call_params=NULL, $fetchMode=PDO::FETCH_ASSOC) {
		return $this->query($multi_call_params)->fetch($fetchMode);
	}

	/**
	 * 获取多条条结果
	 * PDO::FETCH_ASSOC：指定获取方式，将对应结果集中的每一行作为一个由列名索引的数组返回。
	 * 如果结果集中包含多个名称相同的列，则PDO::FETCH_ASSOC每个列名只返回一个值
	 * @param string $multi_call_params
	 * @param int $fetchMode
	 */
	function fetchAll($multi_call_params=NULL, $fetchMode=PDO::FETCH_ASSOC) {
		return $this->query($multi_call_params)->fetchAll($fetchMode);
	}

	/**
	 * 
	 * PDO::FETCH_UNIQUE:只取唯一值
	 * PDO::FETCH_COLUMN:指定获取方式，从结果集中的下一行返回所需要的那一列。
	 * @param string $multi_call_params
	 */
	function fetchAllUnique($multi_call_params=NULL) {
		return $this->query($multi_call_params)->fetchAll(PDO::FETCH_COLUMN|PDO::FETCH_UNIQUE, 0);
	}

	/**
	 * 获取一条数据的第一个字段
	 * @param string $multi_call_params
	 */
	function fetchColumn($multi_call_params=NULL) {
		return $this->query($multi_call_params)->fetchColumn();
	}

	/**
	 * 获取一条数据，以数字索引的方式返回
	 * PDO::FETCH_NUM:指定获取方式，将对应结果集中的每一行作为一个由列号索引的数组返回，从第 0 列开始。
	 * @param string $multi_call_params
	 */
	function fetchIndexed($multi_call_params=NULL) {
		return $this->fetch($multi_call_params, PDO::FETCH_NUM);
	}

	/**
	 * 获取去重后的结果集数量
	 * @param string $distinctFields
	 */
	function recordsCount($distinctFields = '') {
		$this->fields = $distinctFields ? "count(DISTINCT $distinctFields)" : 'count(*)';
		return $this->fetchColumn();
	}

	/**
	 * 插入一条数据
	 * @param unknown $arr
	 * @return boolean
	 */
	function insert($arr) {
		if ( empty($arr) ) return false;

		$comma = '';
		$setFields = '';
		foreach($arr as $key => $value) {
			if (is_array($value)) {
				$setFields .= "{$comma} `{$key}`=" . current($value);
			} else {
				$params[] = $value;
				$setFields .= "$comma `$key`=?";
			}
			$comma = ',';
		}

		$sql = "INSERT INTO  `$this->tableName` set {$setFields}";
		$this->db->execute($sql, $params);
		return $this->db->lastInsertId();
	}

	/**
	 * 不存在在插入，存在则更新
	 * @param array $arr
	 * @param string $upstr
	 * @return boolean
	 */
	function insertOrUpdate($arr,$upstr = null) {
		if ( empty($arr) ) return false;

		$comma = '';
		$setFields = '';
		foreach($arr as $key => $value) {
			if (is_array($value)) {
				$setFields .= "{$comma} `{$key}`=" . current($value);
			} else {
				$params[] = $value;
				$setFields .= "$comma `$key`=?";
			}
			$comma = ',';
		}
		$upstr = empty($upstr)?$setFields:$upstr;
		$sql = "INSERT INTO  `{$this->tableName}` SET {$setFields} ON DUPLICATE KEY UPDATE {$upstr}";
		return $this->db->execute($sql, $params);
	}

	/**
	 * 批量添加
	 * @param array $arr
	 * @param array $fieldNames
	 * @return boolean
	 */
	public function batchInsert($arr, $fieldNames=array()) {
		if (empty($arr)) return false;

		if (!empty($fieldNames)) {
			$keys = is_array($fieldNames) ? implode(',', $fieldNames) : $fieldNames;
		} else {
			$keys = implode(',', array_keys($arr[0]));
		}

		$sql = 'INSERT INTO '.$this->tblName()." ({$keys}) VALUES ";

		$comma = '';
		$params = array();
		foreach ($arr as $a) {
			$sql .= $comma.'(';
			$comma2 = '';
			foreach($a as $v) {
				$sql .= $comma2.'?';
				$params[] = $v;
				$comma2 = ',';
			}
			$sql .= ')';
			$comma = ',';
		}
		return $this->tbl->exec($sql, $params);
	}


	/**
	 * 更新数据
	 * @param array $arr
	 * @param string&array $condition
	 * @return boolean
	 */
	function update($arr, $condition = '') {
		if(empty($arr)) return false;
		$setFields = '';
		$params = array();
		if(is_array($arr)){
			$comma = '';
			foreach($arr as $key => $value) {
				//add database real string
				if (is_array($value)) {
					$setFields .= "{$comma} `{$key}`=" . current($value);
				} else {
					$params[] = $value;
					$setFields .= "{$comma} `{$key}`=?";
				}
				$comma = ',';
			}
		}
		else{
			$setFields = $arr;
		}
		$sql = "UPDATE `{$this->tableName}` set {$setFields}";
		if (!empty($condition)) {
			if (is_array($condition)) {
				$sql .= ' WHERE '.$condition[0];
				$params = array_merge($params, $this->autoarr($condition[1]));
			} else {
				$sql .= ' WHERE '.$this->db->quote($condition);
				$params = null;
			}
		}
		return $this->db->execute($sql, $params);
	}
	
	/**
	 * 更新数据
	 * @param array $arr
	 * @param string&array $condition
	 * @return boolean
	 */
	function updatebak($arr, $condition = '') {
		if ( empty($arr) ) return false;
		$comma = '';
		$setFields = '';
		foreach($arr as $key => $value) {
			//add database real string
			if (is_array($value)) {
				$setFields .= "{$comma} `{$key}`=" . current($value);
			} else {
				$params[] = $value;
				$setFields .= "{$comma} `{$key}`=?";
			}
			$comma = ',';
		}
		$sql = "UPDATE `{$this->tableName}` set {$setFields}";
		if (!empty($condition)) {
			if (is_array($condition)) {
				$sql .= ' WHERE '.$condition[0];
				$params = array_merge($params, $this->autoarr($condition[1]));
			} else {
				$sql .= ' WHERE '.$this->db->quote($condition);
				$params = null;
			}
		}
		return $this->db->execute($sql, $params);
	}

	/**
	 * 删除一条数据
	 * @param string $condition
	 * @param array $params
	 */
	function delete($condition = '', $params = null) {
		$sql = "DELETE FROM `$this->tableName`";
		if (!empty($condition)) {
			if (!empty($params)) { //using prepared statement.
				if (!is_array($params)) $params = array($params);
				$sql .= ' WHERE '.$condition;
			} else {
				$sql .= ' WHERE '.$this->db->quote($condition);
			}
		}
		return $this->db->execute($sql, $params);
	}

	/**
	 * 删除表
	 */
	function dropTable() {
		return $this->db->execute("DROP TABLE $this->table");
	}

	private function reset() {
		$this->fields = '*';
		$this->join = '';
		$this->where = '';
		$this->having = '';
		$this->order = '';
		$this->group = '';
		$this->distinct = false;
		$this->userSql = '';
		$this->limit = '';
	}

	/**
	 * 执行一条SQL语句
	 * @param unknown $sql
	 * @param string $params
	 */
	function exec($sql, $params = NULL){
		if (func_num_args() == 2) {
			$params = $this->autoarr($params);
		} else {
			$params = func_get_args();
			array_shift($params);
		}
		return $this->db->execute($sql, $params);
	}

	private function autoarr($params) {
		if (!is_null($params) && !is_array($params)) $params = array($params);
		return $params;
	}
}
