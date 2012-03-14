<?php
/**
 * This tool can ba used to retrieve contents from the discogs.com database.
 * Only public informations thru the API v2 only.
 *  - No Auth
 *  - No Write
 * See: http://www.discogs.com/developers/
 */
namespace qad\discogs;
use Iterator, RuntimeException;

// {{{ RateLimitException

class RateLimitException extends RuntimeException
{
	private $type;
	public function getType() { return $this->type; }
	private $reset;
	public function getReset() { return $this->reset; }
	function __construct(array $header)
	{
		foreach( $header as $h )
			if( preg_match('/^X-RateLimit-Type: (\w+)$/',$h,$m) )
				$this->type = $m[1];
			elseif( preg_match('/^X-RateLimit-Reset: (\d+)$/',$h,$m) )
				$this->reset = $m[1];
	}
}

// }}}

/**
 * Ask the Discogs API v2 to retrive it's precious contents.
 * The "powerfull" process can deal with pagination and send new request only of it requiered.
 * Warning: This class is totally not communications errors safe, almost not tested.
 * Example: retrieve all releases from a "Zappa" search.
 * ---
 * $d = new Discogs("MyPersonalClient/0.1 +http://mypersonalclient.com");
 * foreach( $d->searchRelease("Zappa") as $id => $release )
 *   var_dump( $id );
 * ---
 * Example: retrieve information about one release.
 * ---
 * var_dump( $d->release('2754221') );
 * ---
 */
class Discogs implements Iterator
{
	// {{{ --members

	private $root_url = 'http://api.discogs.com/';
	private $user_agent = false;

	private $data = null;
	private $header = null;
	private $command = null;
	private $query = array();
	private $index = null;
	private $accessor = null;

	// }}}
	// {{{ __construct, __clone

	/**
   * Construct a new instance ready to be used.
   * Discogs API documentation said:
   *   "
   *   Your application must provide a User-Agent string that identifies itself – preferably something that follows RFC 1945. Some good examples include:
   *
   *     - AwesomeDiscogsBrowser/0.1 +http://adb.example.com
   *     - LibraryMetadataEnhancer/0.3 +http://example.com/lime
   *     - MyDiscogsClient/1.0 +http://mydiscogsclient.org
   *
   *   Please don’t just copy one of those! Make it unique so we can let you know if your application starts to misbehave – the alternative is that we just silently block it, which will confuse and infuriate your users.
   *   "
   * Params:
   *   string $user_agent = The User-Agent used to query the Discogs API.
   */
	function __construct($user_agent)
	{
		assert('is_string($user_agent)');
		assert('preg_match("/^\w+\/[\d.]+ \+?.+$/",$user_agent)');
		$this->user_agent = $user_agent;
	}

	/**
	 * Internally used to avoid conflict with members.
	 */
	function __clone()
	{
		$this->data = null;
		$this->header = null;
		$this->command = null;
		$this->query = array();
		$this->accessor = null;
	}

	// }}}
	// {{{ searchRelease

	/**
	 * Return an Iterator used to retrieve all releases that match the query.
	 * Params:
	 *   string $query = The query.
	 * Returns:
	 *   Discogs = A Iterator to use with foreach().
	 */
	function searchRelease($query)
	{
		assert('is_string($query)');

		$obj = clone $this;
		$data =& $obj->data;

		$obj->command = 'database/search';
		$obj->query = array('q'=>$query,'type'=>'release','page'=>1);
		$obj->accessor = function($index, &$key=null)use(&$data){
			assert('is_object($data)');
			assert('is_array($data->results)');
			assert('is_integer($index) and $index>=0');
			if( ! isset($data->results[$index]) ) return false;
			assert('is_numeric($data->results[$index]->id)');
			$key = $data->results[$index]->id;
			return $data->results[$index];
		};

		return $obj;
	}

	// }}}
	// {{{ release

	/**
	 * Return an object containing all informations about a release extracted from Discogs.com.
	 * Params:
	 *   numeric $id = The unique ID of the release (on Discogs).
	 * Returns:
	 *   object = All avaiables informatinos for this release. var_dump() it.
	 */
	function release($id)
	{
		assert('is_numeric($id)');

		$obj = clone $this;

		$obj->command = "releases/$id";
		$obj->updateData(null);
		assert('is_object($obj->data)');
		return $obj->data;
	}

	// }}}
	// {{{ image

	/**
	 * Leech the image data from Discogs.com and return it to the client.
	 * Take care of sending the User-Agent defined in __construct().
	 * Params:
	 *   string $uri = The image URL.
	 */
	function image($uri, &$header=null)
	{
		assert('is_string($uri)');

		$header = array();

		$obj = clone $this;

		$obj->command = '';
		$obj->root_url = $uri;
		$obj->updateData(null,null,true);
		assert('is_string($obj->data)');

		foreach( $obj->header as $h )
			if( preg_match('/^(?:Content-Type|Expires|Cache-Control|Content-Length|Date):/i',$h) )
				array_push($header,$h);

		return $obj->data;
	}

	// }}}
	// {{{ updateData

	/**
	 * Used internally to retrieve a new bunch of data from Discogs.com.
	 * $this->command should be setted before calling this function.
	 * Params:
	 *   array $query = The GET parameters to pass to the API.
	 *   null $query = No GET paramters will be sent.
	 *   numeric $page = Used to retrieve a specific page instead of the first one.
	 *   null $page = No pagination GET parameters will be sent.
	 *   bool $raw = Indiquate to decode JSON or return the raw data.
	 * Todo:
	 *   - Manage connection errors.
	 *   - Manage decode errors.
	 */
	private function updateData($query,$page=null,$raw=false)
	{
		assert('is_array($query) or is_null($query)');
		assert('is_numeric($page) or is_null($page)');
		assert('is_string($this->command)');

		if( $page and $query ) $query['page'] = $page;

		$this->index = 0;

		$this->data = file_get_contents(
			sprintf('%s%s%s',
				$this->root_url,
				$this->command,
				$query?'?'.http_build_query($query):''),
			false,
			stream_context_create(array('http'=>array(
		    'method'=>'GET',
				'header'=>sprintf('User-Agent: %s\r\n',$this->user_agent)))));

		foreach( $http_response_header as $h )
			if( 'X-RateLimit-Limit: 0' == $h )
				throw new RateLimitException($http_response_header);

		$this->header = $http_response_header;

		if( ! $raw ) $this->data = json_decode($this->data);
	}

	// }}}
	// {{{ rewind, current, key, valid, next

	function rewind()
	{
		$this->index = 0;
		if( ! $this->data )
			$this->updateData($this->query);
		assert('is_object($this->data)');
		assert('is_object($this->data->pagination)');
	}

	function current()
	{
		assert('is_callable($this->accessor)');
		$f = $this->accessor;
		$r =  $f($this->index);
		assert('is_object($r)');
		return $r;
	}

	function key()
	{
		assert('is_callable($this->accessor)');
		$f = $this->accessor;
		$f($this->index, $k);
		assert('is_numeric($k)');
		return $k;
	}

	function next()
	{
		assert('is_integer($this->index)');
		$this->index++;
	}

	function valid()
	{
		assert('is_callable($this->accessor)');
		assert('is_integer($this->index)');
		assert('is_object($this->data)');
		assert('is_object($this->data->pagination)');
		assert('is_numeric($this->data->pagination->page)');
		assert('is_numeric($this->data->pagination->per_page)');
		assert('is_numeric($this->data->pagination->items)');

		if( $this->index >= $this->data->pagination->per_page
			and $this->data->pagination->page < $this->data->pagination->pages )
		{
			$this->updateData($this->query,$this->data->pagination->page+1);
			assert('is_object($this->data)');
			assert('is_object($this->data->pagination)');
			//$this->next();
		}

		$f = $this->accessor;
		$r = $f($this->index);
		assert('is_array($r) or is_object($r) or $r===false');
		return $r;
	}

	// }}}
}
/*
  $d = new Discogs("MyPersonalClient/0.1 +http://mypersonalclient.com");
	//var_dump( $d->release('2754221')->images );
	$d->image('http://api.discogs.com/image/R-2754221-1299524356.jpeg',$header);
	var_dump($header);
 */
