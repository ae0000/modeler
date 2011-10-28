<?php defined('SYSPATH') OR die('No direct access allowed.');
/**
* Database wrapper for validating and filtering data.
*
* @package Modeler
* @category Modeler
* @author Andrew Edwards
* @copyright (c) 2010 Andrew Edwards
*/
class Modeler_Core extends Model
{
	const VERSION = 0.2;

	// The database table name
	protected $_table_name = '';
	protected $_messages_name = '';
	protected $_validated = FALSE;
	protected $_fields = array();
	protected $_filters = array();
	protected $_rules = array();
	protected $_file_rules = array();
	protected $_callbacks = array();
	protected $_data = array();
	protected $_files = array();
	protected $_grouped_data = array();
	protected $_cleaned_data = array();
	protected $_default_value = NULL;
	protected $_validation;
	protected $_validation_error_code = -1;
	protected $cache;
	protected $_date_format = '%a, %e %b %Y %k:%i'; // Mon, 02 Feb 2011 1:21
	protected $_dirty; // For vars not in the class, but you need access to

	/**
	 * Constructor
	 * Sets up the modeler with the internal fields built from the rules. This
	 * means that the models will not be able to use any other class variable
	 * unless it has a defined rule.
	 * 
	 * @access public
	 * @return void
	 */
	public function __construct()
	{
		$this->_fields = array_keys($this->_rules);
		$this->cache = Cache::instance();

		if (empty($this->_messages_name))
		{
			$this->_messages_name = $this->_table_name;
		}
	}
	
	/**
	 * Only add the fields rules if it exists in the data OR it
	 * has a rule of 'not_empty'
	 */
	private function add_field_to_validation($rules, $index)
	{
		if (array_key_exists($index, $this->_data) 
			OR array_key_exists('not_empty', $rules))
		{
			$this->_validation->rules($index, $rules);
		}
	}

	/**
	 * This will run defined filters on the variables  
	 * 
	 * @param array $array_in 
	 * @access protected
	 * @return array The filtered data
	 */
	protected function run_filters(array $array_in)
	{
		// Run any filters (atm the type of functions callable is limited)
		// TODO: Add other filter options, make if/else more robust
		// NOTE: A lot of the logic for the filters is taken from
		//  kohana validation class
		foreach ($this->_filters as $field => $filters)
		{
			if (isset($array_in[$field]))
			{
				foreach ($filters as $filter)
				{
					if (strpos($filter, '::') === FALSE)
					{
						// Use a function call
						$function = new ReflectionFunction($filter);

						// Call $function($this[$field], $param, ...) with Reflection
						$array_in[$field] = $function->invokeArgs(
							array($array_in[$field])
						);
					}
					else
					{
						// We are assuming this is a static class
						// Split the class and method of the rule
						list($class, $method) = explode('::', $filter, 2);

						// Use a static method call
						$method = new ReflectionMethod($class, $method);

						// Call $Class::$method($this[$field], $param, ...) with Reflection
						$array_in[$field] = $method->invokeArgs(
							NULL, // This is NULL for static methods
							array($array_in[$field])
						);
					}
				}
			}
		}

		// Run trim
		return array_map('trim', $array_in);
	}

	/**
	 * This is the function that validates the data in the models  
	 * 
	 * @access public
	 * @return void
	 */
	public function validate()
	{
		// Run filters on data
		$this->_data = $this->run_filters($this->_data);

		// Check to see if the data matches the rules
		$this->_validation = Validation::factory($this->_data);

		// Add the rules (only if the field is present and does not contain 'not_empty'
		foreach ($this->_rules as $field => $value)
		{
			$this->_validation->rules($field, $value);
		}
		
		if ( ! $this->_validation->check())
		{
			throw new Modeler_Exception(
				'Data validation failed', 
				$this->_validation->errors($this->_messages_name),
				$this->_validation_error_code);
		}
	}

	/**
	 * validate_files  
	 * 
	 * @access public
	 * @return void
	 */
	public function validate_files()
	{
		// Check to see if the data matches the rules
		$this->_validation = Validation::factory($this->_files);

		// Add the rules (only if the field is present and does not contain 'not_empty'
		foreach ($this->_file_rules as $field => $value)
		{
			$this->_validation->rules($field, $value);
		}
		
		if ( ! $this->_validation->check())
		{
			throw new Modeler_Exception(
				'Data validation failed', 
				$this->_validation->errors($this->_messages_name),
				$this->_validation_error_code);
		}
	}

	protected function group_validate()
	{
		// For each of the arrays in group data we need to load them into a temp
		//  data object, validate it, then do the next one

		// Keep track of which keys we need to clear
		$keys_to_clear = array();
		foreach ($this->_grouped_data as $group)
		{
			// Clear any keys we need to
			foreach ($keys_to_clear as $kill_key)
			{
				if (isset($this->_data[$kill_key]))
				{
					unset($this->_data[$kill_key]);
				}
			}

			// Get the keys we need to clear before we begin
			$keys_to_clear = array_keys($group);

			// Add the data in
			foreach ($group as $key => $value)
			{
				$this->$key = $value;
			}

			// Now start the validation etc.
			$this->validate();

			// Now its filtered and valid, save it back into clean data
			$cleaned_group = array();
			foreach ($group as $key => $value)
			{
				$cleaned_group[$key] = $this->_data[$key];
			}

			$this->_cleaned_data[] = $cleaned_group;
		}
	}

	protected function insert_grouped_data(Database_Query_Builder $insert, array $fields, $no_null=FALSE)
	{
		// Add the values in grouped data to the insert, then execute
		$row_values = array();
		foreach ($this->_cleaned_data as $group)
		{
			$row_values = array();
			foreach ($fields as $field)
			{
				if (array_key_exists($field, $group))
				{
					$value = $group[$field];
				}
				else
				{
					$value = $this->$field;
				}

				// If the value is NULL but we do not want to insert nulls, then
				// insert '' instead
				if ($no_null AND $value === NULL)
				{
					$value = '';
				}
				$row_values[] = $value;
			}

			// Add the values
			$insert->values($row_values);
		}

		// Execute the insert and return the result
		return $insert->execute();
	}

	protected function add_rule($field, $rule, array $params = NULL)
	{
		$this->_rules[$field][] = $rule;
	}

	protected function add_callback($field, array $callback)
	{
		$this->_callbacks[$field][] = $callback;
	}
	
	/**
	 * Adds 'not_empty' to the input fields rules
	 *
	 * @param mixed $required_fields 
	 * @access protected
	 * @return void
	 */
	protected function not_empty($required_fields)
	{
		if ( ! is_array($required_fields))
		{
			$required_fields = func_get_args();
		}

		foreach ($required_fields as $field)
		{
			if ( ! isset($this->_rules[$field]))
			{
				$this->_rules[$field] = array();
			}
			
			$this->_rules[$field][]  = array('not_empty');
		}
	}

	/**
	 * Magic get method, gets model properties from the db
	 *
	 * @param string $key the field name to look for
	 *
	 * @return String
	 */
	public function __get($key)
	{
		if (array_key_exists($key, $this->_data)) return $this->_data[$key];

		// Return default
		return $this->_default_value;
	}

	/**
	 * Magic get method, gets model properties from the db
	 *
	 * @param string $key   the field name to set
	 * @param string $value the value to set to
	 *
	 */
	public function __set($key, $value)
	{
		
		if (in_array($key, $this->_fields))
		{
			$this->_data[$key] = $value;
			$this->_validated = FALSE;
			return;
		}
		// TODO probably should throw an exception if we fail to find a key
	}

	/**
	 * This is for data you need access to outside of defined rules. In other
	 * words its data that will not be validated.
	 * 
	 * @param mixed $dirty 
	 * @access public
	 * @return void
	 */
	public function set_dirty($dirty)
	{
		$this->_dirty = $dirty;
	}
	

	/**
	 * Gets an array version of the model
	 *
	 * @return array
	 */
	public function as_array()
	{
		return $this->_data;
	}

	/**
	 * Use this to set the fields in the modeler  
	 * 
	 * @param array $data The fields. Eg. array('username' => 'bob');
	 * @access public
	 * @return Modeler_Core
	 */
	public function set_fields(array $data)
	{
		foreach ($data as $key => $value)
		{
			$this->$key = $value;
		}

		return $this;
	}
	
	/**
	 * Sets the files in the modeler to validate  
	 * 
	 * @param array $data The array of files
	 * @access public
	 * @return Modeler_Core
	 */
	public function set_files(array $data)
	{
		foreach ($data as $key => $value)
		{
			$this->_files[$key] = $value;
		}

		return $this;
	}

	/**
	 * This allows you to set arrays of arrays. For example:
	 *		array(
	 *			array('id' => 123, 'name' => 'Bob'),
	 *			array('id' => 143, 'name' => 'Frank'),
	 *		);
	 *
	 * Each of the items in the array will be subjected to the validation of the
	 * class.
	 * 
	 * @param array $data 
	 * @access public
	 * @return void
	 */
	public function set_grouped_fields(array $grouped_data)
	{
		// We need to reset the cleaned data too
		$this->_cleaned_data = array();
		$this->_grouped_data = $grouped_data;
	}

	/**
	 * Returns errors for this model
	 *
	 * @param string $lang the messages file to use
	 *
	 * @return array
	 */
	public function errors($lang = NULL)
	{
		return $this->_validation != NULL ? $this->_validation->errors($lang) : array();
	}

	protected function select_fields(array $fields)
	{
		$selected = array();
		foreach ($fields as $field)
		{
			$selected[$field] = $this->$field;
		}
		return $selected;
	}

	protected function select_fields_if_not_empty(array $fields)
	{
		$selected = array();
		foreach ($fields as $field)
		{
			if ($this->$field !== $this->_default_value)
			{
				$selected[$field] = $this->$field;
			}
		}
		return $selected;
	}

	public function get_rules()
	{
		return $this->_rules;
	}
}

class Modeler_Exception extends Kohana_Exception
{
	public $errors;
	
	public function __construct($message, array $variables = NULL, $code = 0)
	{
		parent::__construct($message, $variables, $code);
		$this->errors = $variables;
	}

	public function __toString()
	{
		return $this->message;
	}
}
