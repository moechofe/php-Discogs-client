Discogs API v2 public client for PHP5.3
=======================================

This is a small helper to retrieve public information from discogs.com using the API v2.

Actually, ther is only two functions implemented:
- [Search for all releases][search]
- [Retrieves a release][release]

[release]: http://www.discogs.com/developers/resources/database/release.html "Database/Releases"
[search]: http://www.discogs.com/developers/resources/database/search-endpoint.html "Database/Search"

Example:
	$d = new Discogs("MyPersonalClient/0.1 +http://mypersonalclient.com");
	foreach( $d->searchRelease("Zappa") as $id => $release )
		var_dump( $id );
	var_dump( $d->release('2754221') );

