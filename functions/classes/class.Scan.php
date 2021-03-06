<?php

/**
 *	phpIPAM SCAN and PING class
 */

class Scan extends Common_functions {

	/**
	 * public variables
	 */
	public $addresses;						//(array of objects) to store addresses, address ID is array index
	public $php_exec = null;				//(int) php executable file
	public $debugging = false;				//(bool) debugging flag
	public $icmp_type = "ping";				//(varchar) default icmp type

	// set date for use throughout script
	private $now     = false;               // time format
    private $nowdate = false;               // date format

	/**
	 * protected variables
	 */
	protected $icmp_timeout = 1;			//(int) icmp timeout
	protected $icmp_count = 1;				//(int) icmp retries
	protected $icmp_exit = true;			//(boolean) exit or return icmp status

	/**
	 * object holders
	 */
	protected $Result;						//for Result printing
	protected $Database;					//for Database connection




	/**
	 * __construct function
	 *
	 * @access public
	 * @param Database_PDO $database
	 * @return void
	 */
	public function __construct (Database_PDO $database) {
		# Save database object
		$this->Database = $database;
		// set time
		$this->set_now_time ();
		// get config
		$this->read_config ();
		// set type
		$this->reset_scan_method ($this->config->method);
		// set php exec
		$this->set_php_exec ();
	}

	/**
	 * Sets execution start date in time and date format
	 *
	 * @access private
	 * @return void
	 */
	private function set_now_time () {
    	$this->nowdate  = date("Y-m-d H:i:s");
    	$this->now      = strtotime($this->nowdate);
	}

	/**
	 * This functin resets the scan method, for cron scripts
	 *
	 * @access public
	 * @param mixed $method
	 * @return void
	 */
	public function reset_scan_method ($method) {
		$this->icmp_type = $method;
	}

	/**
	 * Sets php exec
	 *
	 * @access private
	 * @return void
	 */
	private function set_php_exec () {
		$this->php_exec = PHP_BINDIR."/php";
	}

	/**
	 * Fetch all objects from specified table in database
	 *
	 * @access public
	 * @param mixed $table
	 * @param mixed $sortField (default:id)
	 * @return void
	 */
	public function fetch_all_objects ($table=null, $sortField="id") {
		# null table
		if(is_null($table)||strlen($table)==0) return false;
		# fetch
		try { $res = $this->Database->getObjects($table, $sortField); }
		catch (Exception $e) {
			$this->Result->show("danger", _("Error: ").$e->getMessage());
			return false;
		}
		# result
		return sizeof($res)>0 ? $res : false;
	}

	/**
	 * Fetches specified object specified table in database
	 *
	 * @access public
	 * @param mixed $table
	 * @param mixed $method (default: null)
	 * @param mixed $id
	 * @return void
	 */
	public function fetch_object ($table=null, $method=null, $id) {
		# null table
		if(is_null($table)||strlen($table)==0) return false;
		# null method
		$method = is_null($method) ? "id" : $this->Database->escape($method);

		# ignore 0
		if($id===0 || is_null($id)) {
			return false;
		}
		# check cache
		elseif(isset($this->table[$table][$method][$id]))	{
			return $this->table[$table][$method][$id];
		}
		else {
			try { $res = $this->Database->getObjectQuery("SELECT * from `$table` where `$method` = ? limit 1;", array($id)); }
			catch (Exception $e) {
				$this->Result->show("danger", _("Error: ").$e->getMessage());
				return false;
			}
			# save to cache array
			if(sizeof($res)>0) {
				$this->table[$table][$method][$id] = (object) $res;
				return $res;
			}
			else {
				return false;
			}
		}
	}


	/**
	 * Get maxumum number of hosts for netmask
	 *
	 * @access public
	 * @param mixed $netmask
	 * @param mixed $ipversion
	 * @param bool $strict (default: true)
	 * @return void
	 */
	public function get_max_hosts ($netmask, $ipversion, $strict=true) {
		if($ipversion == "IPv4")	{ return $this->get_max_IPv4_hosts ($netmask, $strict); }
		else						{ return $this->get_max_IPv6_hosts ($netmask, $strict); }
	}

	/**
	 * Get max number of IPv4 hosts
	 *
	 * @access public
	 * @param mixed $netmask
	 * @return void
	 */
	public function get_max_IPv4_hosts ($netmask, $strict) {
		if($netmask==31)			{ return 2; }
		elseif($netmask==32)		{ return 1; }
		elseif($strict===false)		{ return (int) pow(2, (32 - $netmask)); }
		else						{ return (int) pow(2, (32 - $netmask)) -2; }
	}

	/**
	 * Get max number of IPv6 hosts
	 *
	 * @access public
	 * @param mixed $netmask
	 * @return void
	 */
	public function get_max_IPv6_hosts ($netmask, $strict) {
		return gmp_strval(gmp_pow(2, 128 - $netmask));
	}

	/**
	 * Parses address
	 *
	 * @access public
	 * @param mixed $address
	 * @param mixed $netmask
	 * @return void
	 */
	public function get_network_boundaries ($address, $netmask) {
		# make sure we have dotted format
		$address = $this->transform_address ($address, "dotted");
		# set IP version
		$ipversion = $this->get_ip_version ($address);
		# return boundaries
		if($ipversion == "IPv4")	{ return $this->get_IPv4_network_boundaries ($address, $netmask); }
		else						{ return $this->get_IPv6_network_boundaries ($address, $netmask); }
	}

	/**
	 * Returns IPv4 network boundaries
	 *
	 *	network, host ip (if not network), broadcast, bitmask, netmask
	 *
	 * @access private
	 * @param mixed $address
	 * @param mixed $netmask
	 * @return void
	 */
	private function get_IPv4_network_boundaries ($address, $netmask) {
		# Initialize PEAR NET object
		$this->initialize_pear_net_IPv4 ();
		# parse IP address
		$net = $this->Net_IPv4->parseAddress( $address.'/'.$netmask );
		# return boundaries
		return (array) $net;
	}

	/**
	 * Returns IPv6 network boundaries
	 *
	 *	network, host ip (if not network), broadcast, bitmask, netmask
	 *
	 * @access private
	 * @param mixed $address
	 * @param mixed $netmask
	 * @return void
	 */
	private function get_IPv6_network_boundaries ($address, $netmask) {
		# Initialize PEAR NET object
		$this->initialize_pear_net_IPv6 ();
		# parse IPv6 subnet
		$net = $this->Net_IPv6->parseaddress( "$address/$netmask");
		# set network and masks
		$out = new StdClass();
		$out->start		= $net['start'];
		$out->network 	= $address;
		$out->netmask 	= $netmask;
		$out->bitmask 	= $netmask;
		$out->broadcast = $net['end'];		//highest IP address
		# result
		return (array) $out;
	}

	/**
	 * Fetches all IP addresses in subnet
	 *
	 * @access public
	 * @param int $subnetId
	 * @param mixed $order
	 * @param mixed $order_direction
	 * @return objects addresses
	 */
	public function fetch_subnet_addresses ($subnetId, $order=null, $order_direction=null) {
		# set order
		if(!is_null($order)) 	{ $order = array($order, $order_direction); }
		else 					{ $order = array("ip_addr", "asc"); }

		try { $addresses = $this->Database->getObjectsQuery("SELECT * FROM `ipaddresses` where `subnetId` = ? order by `$order[0]` $order[1];", array($subnetId)); }
		catch (Exception $e) {
			$this->Result->show("danger", _("Error: ").$e->getMessage());
			return false;
		}
		# save to addresses cache
		if(sizeof($addresses)>0) {
			foreach($addresses as $k=>$address) {
				# add decimal format
				$address->ip = $this->transform_to_dotted ($address->ip_addr);
				# save to subnets
				$this->addresses[$address->id] = (object) $address;
				$addresses[$k]->ip = $address->ip;
			}
		}
		# result
		return sizeof($addresses)>0 ? $addresses : array();
	}

	/**
	 * Initializes PEAR Net IPv4 object
	 *
	 * @access private
	 * @return void
	 */
	private function initialize_pear_net_IPv4 () {
		//initialize NET object
		if(!is_object($this->Net_IPv4)) {
			require_once( dirname(__FILE__) . '/../../functions/PEAR/Net/IPv4.php' );
			//initialize object
			$this->Net_IPv4 = new Net_IPv4();
		}
	}
	/**
	 * Initializes PEAR Net IPv6 object
	 *
	 * @access private
	 * @return void
	 */
	private function initialize_pear_net_IPv6 () {
		//initialize NET object
		if(!is_object($this->Net_IPv6)) {
			require_once( dirname(__FILE__) . '/../../functions/PEAR/Net/IPv6.php' );
			//initialize object
			$this->Net_IPv6 = new Net_IPv6();
		}
	}









	/**
	 *	@ping @icmp methods
	 *	--------------------------------
	 */

	/**
	 * Function that pings address and checks if it responds
	 *
	 *	any script can be used by extension, important are results
	 *
	 *	0 = Alive
	 *	1 = Offline
	 *	2 = Offline
	 *
	 *	all other codes can be explained in ping_exit_explain method
	 *
	 * @access public
	 * @param mixed $address
	 * @param int $count (default: 1)
	 * @param int $timeout (default: 1)
	 * @param bool $exit (default: false)
	 * @return void
	 */
	public function ping_address ($address, $count=1, $timeout = 1) {
		#set parameters
		$this->icmp_timeout = $timeout;
		$this->icmp_count = $count;

		# escape address
		$address = escapeshellarg($address);

		# make sure it is in right format
		$address = $this->transform_address ($address, "dotted");
		# set method name variable
		$ping_method = "ping_address_method_".$this->icmp_type;
		# ping with selected method
		return $this->$ping_method ($address);
	}

	/**
	 * Ping selected address and return response
	 *
	 *	timeout value: for miliseconds multiplyy by 1000
	 *
	 * @access protected
	 * @param ip $address
	 * @return void
	 */
	protected function ping_address_method_ping ($address) {
		# set ping command based on OS type
		if	(PHP_OS == "FreeBSD" || PHP_OS == "NetBSD")                         { $cmd = $this->config->pingpath." -c $this->icmp_count -W ".($this->icmp_timeout*1000)." $address 1>/dev/null 2>&1"; }
		elseif(PHP_OS == "Linux" || PHP_OS == "OpenBSD")                        { $cmd = $this->config->pingpath." -c $this->icmp_count -w $this->icmp_timeout $address 1>/dev/null 2>&1"; }
		elseif(PHP_OS == "WIN32" || PHP_OS == "Windows" || PHP_OS == "WINNT")	{ $cmd = $this->config->pingpath." -n $this->icmp_count -I ".($this->icmp_timeout*1000)." $address 1>/dev/null 2>&1"; }
		else																	{ $cmd = $this->config->pingpath." -c $this->icmp_count -n $address 1>/dev/null 2>&1"; }

		# execute command, return $retval
	    exec($cmd, $output, $retval);

		# return result for web or cmd
		exit ($retval);
	}

	/**
	 * Ping selected address with PEAR ping package
	 *
	 * @access protected
	 * @param ip $address
	 * @return void
	 */
	protected function ping_address_method_pear ($address) {
		# we need pear ping package
		require_once(dirname(__FILE__) . '/../../functions/PEAR/Net/Ping.php');
		$ping = Net_Ping::factory();

		# check for errors
		if($ping->pear->isError($ping)) {
			$this->throw_exception ("Error: ".$ping->getMessage());
		}
		else {
			//set count and timeout
			$ping->setArgs(array('count' => $this->icmp_timeout, 'timeout' => $this->icmp_timeout));
			//execute
			$ping_response = $ping->ping($address);
			//check response for error
			if($ping->pear->isError($ping_response)) {
				$result['code'] = 2;
			}
			else {
				//all good
				if($ping_response->_transmitted == $ping_response->_received) {
					$result['code'] = 0;
					$this->rtt = "RTT: ". strstr($ping_response->_round_trip['avg'], ".", true);
				}
				//ping loss
				elseif($ping_response->_received == 0) {
					$result['code'] = 1;
				}
				//failed
				else {
					$result['code'] = 3;
				}
			}
		}

		//return result for web or cmd
		exit ($result['code']);
	}

	/**
	 * Ping selected address with fping function
	 *
	 *	Exit status is:
	 *		0 if all the hosts are reachable,
	 *		1 if some hosts were unreachable,
	 *		2 if any IP addresses were not found,
	 *		3 for invalid command line arguments,
	 *		4 for a system call failure.
	 *
	 *	fping cannot be run from web, it needs root privileges to be able to open raw socket :/
	 *
	 * @access public
	 * @param mixed $subnet 	//CIDR
	 * @return void
	 */
	public function ping_address_method_fping ($address) {
		# set command
		$cmd = $this->config->pingpath." -c $this->icmp_count -t ".($this->icmp_timeout*1000)." $address";
		# execute command, return $retval
	    exec($cmd, $output, $retval);

	    # save result
	    if($retval==0) {
	    	$this->save_fping_rtt ($output[0]);
		}

		# return result for web or cmd
		exit ($retval);
	}

	/**
	 * Saves RTT for fping
	 *
	 * @access private
	 * @param mixed $line
	 * @return void
	 */
	private function save_fping_rtt ($line) {
		// 173.192.112.30 : xmt/rcv/%loss = 1/1/0%, min/avg/max = 160/160/160
 		$tmp = explode(" ",$line);

 		# save rtt
		@$this->rtt	= "RTT: ".str_replace("(", "", $tmp[7]);
	}

	/**
	 * Ping selected address with fping function
	 *
	 *	Exit status is:
	 *		0 if all the hosts are reachable,
	 *		1 if some hosts were unreachable,
	 *		2 if any IP addresses were not found,
	 *		3 for invalid command line arguments,
	 *		4 for a system call failure.
	 *
	 *	fping cannot be run from web, it needs root privileges to be able to open raw socket :/
	 *
	 * @access public
	 * @param mixed $subnet 	//CIDR
	 * @return void
	 */
	public function ping_address_method_fping_subnet ($subnet_cidr, $return_result = false) {
		# set command
		$cmd = $this->config->pingpath." -c $this->icmp_count -t ".($this->icmp_timeout*1000)." -Ag $subnet_cidr";
		# execute command, return $retval
	    exec($cmd, $output, $retval);

	    # save result
	    if(sizeof($output)>0) {
	    	foreach($output as $line) {
		    	$tmp = explode(" ",$line);
		    	$out[] = $tmp[0];
	    	}
	    }

	    # save to var
	    $this->fping_result = $out;

	    # return result?
	    if($return_result)		{ return $out; }

		# return result for web or cmd
		exit ($retval);
	}

	/**
	 * Explains invalid error codes
	 *
	 * @access public
	 * @param mixed $code
	 * @return void
	 */
	public function ping_exit_explain ($code) {
		# fetch explain codes
		$explain_codes = $this->ping_set_exit_code_explains ();

		# return code
		return isset($explain_codes[$code]) ? $explain_codes[$code] : false;
	}

	/**
	 * This function sets ping exit code and message mappings
	 *
	 *	http://www.freebsd.org/cgi/man.cgi?query=sysexits&apropos=0&sektion=0&manpath=FreeBSD+4.3-RELEASE&arch=default&format=ascii
	 *
	 *	extend if needed for future scripts
	 *
	 * @access public
	 * @return void
	 */
	public function ping_set_exit_code_explains () {
		$explain_codes[0]  = "SUCCESS";
		$explain_codes[1]  = "OFFLINE";
		$explain_codes[2]  = "ERROR";
		$explain_codes[3]  = "UNKNOWN ERROR";
		$explain_codes[64] = "EX_USAGE";
		$explain_codes[65] = "EX_DATAERR";
		$explain_codes[68] = "EX_NOHOST";
		$explain_codes[70] = "EX_SOFTWARE";
		$explain_codes[71] = "EX_OSERR";
		$explain_codes[72] = "EX_OSFILE";
		$explain_codes[73] = "EX_CANTCREAT";
		$explain_codes[74] = "EX_IOERR";
		$explain_codes[75] = "EX_TEMPFAIL";
		$explain_codes[77] = "EX_NOPERM";
		# return codes
		return $explain_codes;
	}

	/**
	 * Update lastseen field for specific IP address
	 *
	 * @access public
	 * @param int $id
	 * @return void
	 */
	public function ping_update_lastseen ($id) {
		# execute
		try { $this->Database->updateObject("ipaddresses", array("id"=>$id, "lastSeen"=>$this->nowdate), "id"); }
		catch (Exception $e) {
			$this->throw_exception ("Error: ".$e->getMessage());
		}
	}









	/**
	 *	@prepare addresses methods
	 *	--------------------------------
	 */

	/**
	 * Returns all addresses to be scanned or updated
	 *
	 * @access public
	 * @param mixed $type		//discovery, update
	 * @param mixed $subnet
	 * @return void
	 */
	public function prepare_addresses_to_scan ($type, $subnet) {
		# discover new addresses
		if($type=="discovery") 	{ return is_numeric($subnet) ? $this->prepare_addresses_to_discover_subnetId ($subnet) : $this->prepare_addresses_to_discover_subnet ($subnet); }
		# update addresses statuses
		elseif($type=="update") { return $this->prepare_addresses_to_update ($subnet); }
		# fail
		else 					{ $this->throw_exception ("Error: Invalid scan type provided"); }
	}

	/**
	 * Returns array of all addresses to be scanned inside subnet defined with subnetId
	 *
	 * @access public
	 * @param mixed $subnetId
	 * @return void
	 */
	public function prepare_addresses_to_discover_subnetId ($subnetId) {
		//subnet ID is provided, fetch subnet
		$subnet = $this->fetch_object("subnets", "id", $subnetId);
		if($subnet===false)										 { $this->throw_exception ("Error: Invalid subnet ID provided"); }

		# set array of addresses to scan, exclude existing!
		$ip = $this->get_all_possible_subnet_addresses ($subnet->subnet, $subnet->mask);

		# remove existing
		$ip = $this->remove_existing_subnet_addresses ($ip, $subnetId);

		//none to scan?
		if(sizeof($ip)==0)										 { $this->throw_exception ("Error: Didn't find any address to scan!"); }

		//return
		return $ip;
	}

	/**
	 * Fetches all possible subnet addresses
	 *
	 * @access private
	 * @param $subnet		//subnet in decimal format
	 * @param int $mask		//subnet mask
	 * @return void			//array of ip addresses in decimal format
	 */
	private function get_all_possible_subnet_addresses ($subnet, $mask) {
		# make sure we have proper subnet format
		$subnet    = $this->transform_address($subnet, "decimal");
		//fetch start and stop addresses
		$boundaries = (object) $this->get_network_boundaries ($subnet, $mask);
		//create array
		if($mask==32) {
			$ip[] = $this->transform_to_decimal($boundaries->network);
		}
		elseif($mask==31) {
			$ip[] = $this->transform_to_decimal($boundaries->network);
			$ip[] = $this->transform_to_decimal($boundaries->broadcast);
		}
		else {
			//set loop limits
			$start = $this->transform_to_decimal($boundaries->network)+1;
			$stop  = $this->transform_to_decimal($boundaries->broadcast);
			//loop
			for($m=$start; $m<$stop; $m++) {
				$ip[] = $m;
			}
		}
		//return
		return $ip;
	}

	/**
	 * Removes existing addresses from
	 *
	 * @access private
	 * @param mixed $ip				//array of ip addresses in decimal format
	 * @param mixed $subnetId		//id of subnet
	 * @return void
	 */
	private function remove_existing_subnet_addresses ($ip, $subnetId) {
		// get all existing IP addresses in subnet
		$addresses  = $this->fetch_subnet_addresses($subnetId);
		// if some exist remove them
		if(sizeof($addresses)>0 && sizeof(@$ip)>0) {
			foreach($addresses as $a) {
				$key = array_search($a->ip_addr, $ip);
				if($key !== false) {
					unset($ip[$key]);
				}
			}
			//reindex array for pinging
			$ip = array_values(@$ip);
		}
		//return
		return is_array(@$ip) ? $ip : array();
	}

	/**
	 * Returns array of all addresses in subnet
	 *
	 * @access public
	 * @param mixed $subnet
	 * @return void
	 */
	public function prepare_addresses_to_discover_subnet ($subnet) {
		//set subnet / mask
		$subnet_parsed = explode("/", $subnet);
		# result
		$ip = $this->get_all_possible_subnet_addresses ($subnet_parsed[0], $subnet_parsed[1]);
		//none to scan?
		if(sizeof($ip)==0)									{ $this->throw_exception ("Error: Didn't find any address to scan!"); }
		//result
		return $ip;
	}

	/**
	 * Returns array of all addresses to be scanned
	 *
	 * @access public
	 * @param mixed $subnetId
	 * @return void
	 */
	public function prepare_addresses_to_update ($subnetId) {
		// get all existing IP addresses in subnet
		$subnet_addresses = $this->fetch_subnet_addresses($subnetId);
		//create array
		if(sizeof($subnet_addresses)>0) {
			foreach($subnet_addresses as $a) {
				$scan_addresses[$a->id] = $a->ip_addr;
			}
			//reindex
			$scan_addresses = array_values(@$scan_addresses);
			//return
			return $scan_addresses;
		}
		else {
			return array();
		}
	}


}







/**
 *	@scan helper functions
 *		fir threading
 * ------------------------
 */

/**
 *	Ping address helper for CLI threading
 *
 *	used for:
 *		- icmp status update (web > ping, pear)
 *		- icmp subnet discovery (web > ping, pear)
 *		- icmp status update (cli)
 *		- icmp discovery (cli)
 */
function ping_address ($address) {
	global $Scan;
	//scan
	return $Scan->ping_address ($address);
}

/**
 *	Telnet address helper for CLI threading
 */
function telnet_address ($address, $port) {
	global $Scan;
	//scan
	return $Scan->telnet_address ($address, $port);
}

/**
 *	fping subnet helper for fping threading, all methods
 */
function fping_subnet ($subnet_cidr, $return = true) {
	global $Scan;
	//scan
	return $Scan->ping_address_method_fping_subnet ($subnet_cidr, $return);
}
