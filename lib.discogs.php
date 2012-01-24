<?php
/**
 * This tool can ba used to retrieve contents from the discogs.com database.
 * Only public informations thru the API v2 only.
 *  - No Auth
 *  - No Write
 * See: http://www.discogs.com/developers/
 */
namespace qad\discogs;
use Iterator;

/**
 * Ask the Discogs API v2 to retrive it's precious contents.
 * The "powerfull" process can deal with pagination and send new request only of it requiered.
 * Warning: This class is totally not communications errors safe, almost not tested.
 * Example: retrieve all releases from a "Zappa" search.
 * ---
 * $d = new Discogs("MyPersonalClient/0.1 +http://mypersonalclient.com");
 * foreach( $d->searchRelease("Zappa") as $id => $release )
 *   var_dump( $id );
 */
class Discogs implements Iterator
{
	// {{{ --members

	private $root_url = 'http://api.discogs.com/';
	private $user_agent = false;

	private $data = null;
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

	function __clone()
	{
		$this->data = null;
		$this->query = array();
		$this->accessor = null;
	}

	// }}}
	// {{{ searchRelease

	function searchRelease($query, $page=1)
	{
		assert('is_string($query)');

		$obj = clone $this;
		$data =& $obj->data;

		$obj->query = array('q'=>$query,'type'=>'release','page'=>$page);
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
	// {{{ updateData

	private function updateData($query,$page=null)
	{
		assert('is_array($query)');
		assert('is_numeric($page) or is_null($page)');

		if( $page ) $query['page'] = $page;

		$this->index = 0;

		$this->data = json_decode(file_get_contents(
			sprintf('%s%s?%s',
				$this->root_url,
				'database/search',
				http_build_query($query)),
			false,
			stream_context_create(array('http'=>array(
		    'method'=>'GET',
		    'header'=>sprintf('User-Agent: %s\r\n',$this->user_agent))))));
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

$d = new Discogs("PlaieListeMusicPlayerDeamonClient/12.03 +https://github.com/moechofe/PlaieListe");

$c=0;
//foreach( $d->searchRelease("Stupeflip The Hypnoflip Invasion") as $id => $release )
foreach( $d->searchRelease("The Mothers") as $id => $release )
echo ++$c,': ',$id,PHP_EOL;
var_dump($c);
//var_dump( $id, $release );
