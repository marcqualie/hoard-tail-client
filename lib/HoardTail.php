<?php

class HoardTail {

	const LOG_OFF = 0;
	const LOG_INFO = 1;
	const LOG_WARN = 2;
	const LOG_ERROR = 4;

	private $config = array();
	private $input_handles = array();
	private $log_level = 1;
	private $filters = array();



	/**
	 * Load configuration
	 * @param array $config Config data
	 */
	public function __construct (array $config = array())
	{
		if ($config)
		{
			$this->loadConfig($config);
		}
	}
	public function loadConfig (array $config = array())
	{
		$this->config = $config;
	}



	/**
	 * Output Data
	 */
	private function publish ($input, $line)
	{
		$line = trim($line);
		$data = isset($input['data']) ? $input['data'] : array();
		$data['@host'] = gethostname();
		$data['@source'] = $data['@host'] . ':' . $input['source'];
		foreach ($input['filters'] as $filter)
		{
			$data = $data + $this->apply_filter($line, $filter);
		}

		// Event type
		$event = 'tail';
		if (isset($data['@event']))
		{
			$event = $data['@event'];
			unset($data['@event']);
		}

		foreach ($this->config['output'] as $output)
		{
			$json_encode = json_encode($data);
			$ch = curl_init($output['server'] . '/track/' . $event);
			curl_setopt_array($ch, array(
				CURLOPT_HEADER                  => false,
				CURLOPT_RETURNTRANSFER          => true,
				CURLOPT_POST                    => true,
				CURLOPT_POSTFIELDS              => array(
					'appkey' => $output['appkey'],
					'type' => 'json',
					'data' => $json_encode
					)
			));
			$this->log($json_encode, self::LOG_INFO);
			curl_exec($ch);
			curl_close($ch);
		}
	}



	/**
	 * Apply filters before passing to output
	 */
	private function apply_filter ($line, $filter)
	{

		// Load filter data or return original if not found
		$filter = $this->load_filter($filter);
		if ( ! $filter)
		{
			return array();
		}

		// Check if there is a filter type to match
		if ($filter['type'] === 'regex')
		{
			$data = $this->apply_filter_regex($line, $filter);
		}
		elseif ($filter['type'] === 'function')
		{
			$data = $this->apply_filter_function($line, $filter);
		}
		else
		{
			$data = array();
		}
		foreach ($filter['data'] as $key => $val)
		{
			preg_match_all('/\$([a-zA-Z0-9]+)/', $val, $matches, PREG_SET_ORDER);
			foreach ($matches as $match)
			{
				$val = str_replace($match[0], $data[$match[1]], $val);
			}
			$data[$key] = $val;
		}
		return $data;

	}
	private function apply_filter_regex ($line, $filter)
	{
		$pattern = $filter['pattern'];
		preg_match_all('#<([^>]+)>#', $pattern, $matches, PREG_SET_ORDER);
		$keys = array();
		foreach ($matches as $i => $match)
		{
			$keys[$i] = $match[1];
			$pattern = str_replace($match[0], '(' . $filter['values'][$match[1]] . ')', $pattern);
		}
		preg_match_all('#' . $pattern . '#', $line, $matches, PREG_SET_ORDER);
		$vars = array();
		foreach ($matches[0] as $i => $match)
		{
			if ($i > 0)
			{
				$vars[$keys[$i - 1]] = $match;
			}
		}
		$data = array();
		return $data;
	}
	private function apply_filter_function ($line, $filter)
	{
		$function = $filter['name'];
		if (function_exists($function))
		{
			return $function($line, true);
		}
		return array();
	}



	/**
	 * Get file filter
	 */
	private function load_filter ($name)
	{
		if (isset($this->filters[$name]))
		{
			return $this->filters[$name];
		}
		$file = __DIR__ . '/filter/' . $name . '.json';
		if ( ! file_exists($file))
		{
			$this->filters[$name] = false;
			return false;
		}
		$content = file_get_contents($file);
		$filter = json_decode($content, true);
		if ( ! $filter)
		{
			$this->filters[$name] = false;
			return false;
		}
		if ( ! isset($filter['data']))
		{
			$filter['data'] = array();
		}
		$this->filters[$name] = $filter;
		return $filter;
	}



	/**
	 * Begin to listen to files
	 */
	public function listen ()
	{

		// Do some config checks
		foreach ($this->config['input'] as $index => $input)
		{
			if ( ! isset($input['filters']))
			{
				$input['filters'] = array();
			}
			if (substr($input['source'], -5) === '.json')
			{
				if ( ! in_array('json', $input['filters']))
				{
					$input['filters'][] = 'json';
				}
			}
			$this->config['input'][$index] = $input;
		}

		// Keep listening forever until we say to stop
		$rps = 10;
		while (true)
		{
			// Safe guard against losing file handlers
			if ($count % ($rps * 60) === 0)
			{
				$this->sync_file_handles();
			}
			$count++;

			// Loop over each input to build listenders
			foreach ($this->config['input'] as $input)
			{

				$filename = $input['source'];
				$filehash = md5($filename);
				$file = $this->input_handles[$filehash];
				if ( ! $file)
				{
					continue;
				}
				fseek($file['handle'], $file['position']);
				while ($line = fgets($file['handle']))
				{
					$line = trim($line);
					if ($line)
					{
						$this->publish($input, $line);
					}
				}
				$position = ftell($file['handle']);
				$this->input_handles[$filehash]['position'] = $position;
			}
			usleep( 1000000 / $rps );
		}
	}



	/**
	 * Sync file handlers
	 */
	private function sync_file_handles ()
	{
		foreach ($this->config['input'] as $input)
		{
			$filename = $input['source'];
			$filehash = md5($filename);
			if (file_exists($filename))
			{
				$this->log('Listening to ' . $filename, self::LOG_INFO);
				$handle = fopen($filename, 'r');
				fseek($handle, -1, SEEK_END);
				$position = ftell($handle);
				$this->input_handles[$filehash] = array(
					'handle'       => $handle,
					'position'     => $position
				);
			}
		}
	}



	/**
	 * Logging
	 */
	private function log ($line, $level = 1)
	{
		if ($level & $this->log_level)
		{
			echo "\033[32m[" . date('m/d-H:i:s') . "]\033[0m "  . $line . PHP_EOL;
		}
	}

}