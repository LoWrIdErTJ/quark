<?php
namespace Quark\DataProviders;

use Quark\IQuarkDataProvider;
use Quark\IQuarkModel;
use Quark\IQuarkModelWithCustomPrimaryKey;

use Quark\Quark;
use Quark\QuarkCredentials;
use Quark\QuarkArchException;
use Quark\QuarkConnectionException;

/**
 * Class MySQL
 *
 * @package Quark\DataProviders
 */
class MySQL implements IQuarkDataProvider {
	const FIELD_COUNT_ALL = 'COUNT(*)';

	/**
	 * @var \mysqli $_connection
	 */
	private $_connection;
	private static $_pool = array();

	/**
	 * @return array
	 */
	public static function SourcePool () {
		return self::$_pool;
	}

	/**
	 * @param $name
	 *
	 * @return QuarkCredentials
	 * @throws QuarkArchException
	 */
	public static function SourceGet ($name) {
		if (!isset(self::$_pool[$name]))
			throw new QuarkArchException('MySQL connection \'' . $name . '\' is not pooled');

		return self::$_pool[$name];
	}

	/**
	 * @param                  $name
	 * @param QuarkCredentials $credentials
	 *
	 * @throws QuarkArchException
	 */
	public static function SourceSet ($name, QuarkCredentials $credentials) {
		self::$_pool[$name] = new MySQL();
		self::$_pool[$name]->Connect($credentials);
	}

	/**
	 * @param                  $name
	 * @param QuarkCredentials $credentials
	 */
	public function Source ($name, QuarkCredentials $credentials) {
		$this->Connect($credentials);
		self::$_pool[$name] = $this;
	}

	/**
	 * @param QuarkCredentials $credentials
	 *
	 * @throws QuarkArchException|QuarkConnectionException
	 */
	public function Connect (QuarkCredentials $credentials) {
		$this->_connection = \mysqli_init();

		if (!$this->_connection)
			throw new QuarkArchException('MySQLi initialization fault');

		$options = $credentials->Options();

		if (is_array($options))
			foreach ($options as $key => $value) {
				if (!$this->_connection->options($key, $value))
					throw new QuarkArchException('MySQLi option set error');
			}

		if (!$this->_connection->real_connect(
			$credentials->host,
			$credentials->username,
			$credentials->password,
			$credentials->suffix,
			(int)$credentials->port
		))
			throw new QuarkConnectionException($credentials, Quark::LOG_FATAL);
	}

	/**
	 * @param $model
	 * @param $options
	 * @param $query
	 *
	 * @return bool|\mysqli_result
	 */
	private function _query ($model ,$options, $query) {
		$collection = isset($options['collection'])
			? $options['collection']
			: Quark::ClassName($model);

		$i = 1;
		$query = str_replace(self::_collection($model), '`' . $collection . '`', $query, $i);

		$mode = isset($options['mode'])
			? $options['mode']
			: MYSQLI_STORE_RESULT;

		return $this->_connection->query($query, $mode);
	}

	/**
	 * @param $model
	 *
	 * @return string
	 */
	private static function _collection ($model) {
		return '{collection_' . sha1(print_r($model, true)) . '}';
	}

	/**
	 * @param IQuarkModel $model
	 *
	 * @return string|bool
	 */
	private static function _pk (IQuarkModel $model) {
		return $model instanceof IQuarkModelWithCustomPrimaryKey ? $model->PrimaryKey() : 'id';
	}

	/**
	 * @param string $field
	 *
	 * @return string
	 */
	private function _field ($field) {
		if (!is_string($field)) return '';

		return '`' . $this->_connection->real_escape_string($field) . '`';
	}

	/**
	 * @param $value
	 *
	 * @return bool|float|int|string
	 */
	private function _value ($value) {
		if (!is_scalar($value)) return null;

		$output = $this->_connection->real_escape_string($value);

		return is_string($value) ? '\'' . $output . '\'' : $output;
	}

	/**
	 * @param        $condition
	 * @param string $glue
	 *
	 * @return string
	 */
	private function _condition ($condition, $glue = '') {
		if (!is_array($condition) || sizeof($condition) == 0) return '';

		$output = array();
		$field = '';
		$value = '';

		foreach ($condition as $key => $rule) {
			$field = $this->_field($key);
			$value = $this->_value($rule);

			if (is_array($rule))
				$value = self::_condition($rule, ' AND ');

			switch ($field) {
				case '`$lte`': $output[] = '<=' . $value; break;
				case '`$lt`': $output[] = '<' . $value; break;
				case '`$gt`': $output[] = '>' . $value; break;
				case '`$gte`': $output[] = '>=' . $value; break;
				case '`$ne`': $output[] = '<>' . $value; break;

				case '`$or`':
					$value = self::_condition($rule, ' OR ');
					$output[] = ' (' . $value . ') ';
					break;

				case '`$nor`':
					$value = self::_condition($rule, ' NOT OR ');
					$output[] = ' (' . $value . ') ';
					break;

				default:
					$output[] = !$value ? '' : (is_string($key) ? $field : '') . (is_scalar($rule) ? '=' : '') . $value;
					break;
			}
		}

		return ($glue == '' ? ' WHERE ' : '') . implode($glue == '' ? ' AND ' : $glue, $output);
	}

	/**
	 * @param $options
	 *
	 * @return string
	 */
	private function _cursor ($options) {
		$output = '';

		if (isset($options['limit']))
			$output .= ' LIMIT ' . $this->_connection->real_escape_string($options['limit']);

		if (isset($options['skip']))
			$output .= ' OFFSET ' . $this->_connection->real_escape_string($options['skip']);

		if (isset($options['sort']) && is_array($options['sort'])) {
			$output .= ' ORDER BY ';
			$sort = '';

			foreach ($options['sort'] as $key => $order) {
				switch ($order) {
					case 1: $sort = 'ASC'; break;
					case -1: $sort = 'DESC'; break;
					default: $sort = ''; break;
				}

				$output .= ' ' . $this->_field($key) . ' ' . $sort;
			}
		}

		return $output;
	}

	/**
	 * @param IQuarkModel $model
	 * @param array       $options
	 *
	 * @return mixed
	 */
	public function Create (IQuarkModel $model, $options = []) {
		$keys = array();
		$values = array();

		foreach ($model as $key => $value) {
			$keys[] = $this->_field($key);
			$values[] = $this->_value($value);
		}

		return $this->_query(
			$model,
			$options,
			'INSERT INTO ' . self::_collection($model)
				. ' (' . implode(', ', $keys) . ') '
				. 'VALUES (' . implode(', ', $values) . ')'
		);
	}

	/**
	 * @param IQuarkModel $model
	 * @param array       $options
	 *
	 * @return mixed
	 */
	public function Save (IQuarkModel $model, $options = []) {
		$pk = self::_pk($model);

		if (!isset($model->$pk)) return false;

		return $this->Update($model, array(
			$pk => $model->$pk
		), $options);
	}

	/**
	 * @param IQuarkModel $model
	 * @param array       $options
	 *
	 * @return mixed
	 */
	public function Remove (IQuarkModel $model, $options = []) {
		$pk = self::_pk($model);

		if (!isset($model->$pk)) return false;

		return $this->Delete($model, array(
			$pk => $model->$pk
		), $options);
	}

	/**
	 * @param IQuarkModel $model
	 * @param             $criteria
	 * @param array       $options
	 *
	 * @return array
	 */
	public function Find (IQuarkModel $model, $criteria, $options = []) {
		$output = array();
		$records = $this->_select($model, $criteria, $options);

		if ($records)
			foreach ($records as $i => $record)
				$output[] = $record;

		return $output;
	}

	/**
	 * @param IQuarkModel $model
	 * @param             $criteria
	 * @param array       $options
	 *
	 * @return IQuarkModel
	 */
	public function FindOne (IQuarkModel $model, $criteria, $options = []) {
		$records = $this->Find($model, $criteria, $options + array('limit' => 1));

		return sizeof($records) == 0 ? null : $records[0];
	}

	/**
	 * @param IQuarkModel $model
	 * @param $id
	 * @param $options
	 *
	 * @return IQuarkModel
	 */
	public function FindOneById (IQuarkModel $model, $id, $options = []) {
		return $this->FindOne($model, array(
			self::_pk($model) => $id
		), $options);
	}

	/**
	 * @param       $model
	 * @param       $criteria
	 * @param array $options
	 *
	 * @return bool|\mysqli_result
	 */
	private function _select ($model, $criteria, $options = []) {
		$fields = '*';

		if (isset($options['fields']) && is_array($options['fields'])) {
			$fields = '';
			$key = '';
			$count = sizeof($options['fields']);
			$i = 1;

			foreach ($options['fields'] as $j => $field) {
				$key = false;

				switch ($field) {
					case self::FIELD_COUNT_ALL:
						$key = $field;
						break;

					default:
						$key = $this->_field($field);
						break;
				}

				$fields = $key . ($i == $count || !$key ? '' : ', ');
				$i++;
			}
		}

		return $this->_query(
			$model,
			$options,
			'SELECT ' . $fields . ' FROM ' . self::_collection($model) . $this->_condition($criteria) . $this->_cursor($options)
		);
	}

	/**
	 * @param IQuarkModel $model
	 * @param             $criteria
	 * @param             $options
	 *
	 * @return mixed
	 */
	public function Update (IQuarkModel $model, $criteria, $options) {
		$fields = array();

		foreach ($model as $key => $value)
			$fields[] = $this->_field($key) . '=' . '\'' . $this->_value($value) . '\'';

		return $this->_query(
			$model,
			$options,
			'UPDATE ' . self::_collection($model) . ' SET ' . implode(', ', $fields) . $this->_condition($criteria) . $this->_cursor($options)
		);
	}

	/**
	 * @param IQuarkModel $model
	 * @param             $criteria
	 * @param             $options
	 *
	 * @return mixed
	 */
	public function Delete (IQuarkModel $model, $criteria, $options) {
		return $this->_query(
			$model,
			$options,
			'DELETE FROM ' . self::_collection($model) . $this->_condition($criteria) . $this->_cursor($options)
		);
	}

	/**
	 * @param IQuarkModel $model
	 * @param             $criteria
	 * @param             $limit
	 * @param             $skip
	 * @param             $options
	 *
	 * @return int
	 */
	public function Count (IQuarkModel $model, $criteria, $limit, $skip, $options = []) {
		$result = $this->_select($model, $criteria, $options + array(
			'fields' => array(self::FIELD_COUNT_ALL)
		));

		return !$result ? 0 : (int)$result->fetch_row()[0];
	}
}