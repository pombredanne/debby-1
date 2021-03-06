<?php

namespace alsvanzelf\debby\manager;

use alsvanzelf\debby;
use alsvanzelf\debby\exception;
use alsvanzelf\debby\package;

class composer implements manager {

private $path;
private $executable;
private $packages;
private $required;
private $installed;
private $updatable;

/**
 * setup the environment
 * 
 * @param array $options {
 *              @var $path where the composer.json and composer.lock are placed
 * }
 */
public function __construct(array $options=[]) {
	if (empty($options['path'])) {
		$e = new exception('can not check for composer updates without a path option');
		$e->stop();
	}
	
	$this->path = $options['path'];
	
	/**
	 * get composer executable
	 */
	$this->executable = 'composer';
	if (file_exists($this->path.'composer.phar')) {
		$this->executable = 'php composer.phar';
	}
}

/**
 * manager name for usage in notifications
 * 
 * @return string
 */
public function get_name() {
	return 'composer';
}

/**
 * get a package object for the given name
 * 
 * this will always succeed, if one doesn't exist, one will be created
 * 
 * @param  string           $package_name in vendor/package format, i.e. 'alsvanzelf/debby'
 * @return package\composer
 */
public function get_package_by_name($package_name) {
	if (empty($this->packages[$package_name])) {
		$this->packages[$package_name] = new package\composer($this, $package_name);
	}
	
	return $this->packages[$package_name];
}

/**
 * give a list of all required packages
 * 
 * @return array<package\composer>
 */
public function find_required_packages() {
	if ($this->required === null) {
		if (debby\VERBOSE) {
			debby\debby::log('Checking '.$this->get_name().' for required packages');
		}
		
		if (file_exists($this->path.'composer.json') === false) {
			$e = new exception('can not find composer.json in the path');
			$e->stop();
		}
		
		$composer_json = file_get_contents($this->path.'composer.json');
		$composer_json = json_decode($composer_json, true);
		if (empty($composer_json['require'])) {
			$e = new exception('there are no required packages to check');
			$e->stop();
		}
		
		$this->required = [];
		foreach ($composer_json['require'] as $package_name => $required_version) {
			// skip platform packages like 'ext-curl'
			if (strpos($package_name, '/') === false) {
				continue;
			}
			
			$package = $this->get_package_by_name($package_name);
			$package->mark_required($required_version);
			
			$this->required[$package->get_name()] = $package;
		}
	}
	
	return array_values($this->required);
}

/**
 * give a list of all installed packages
 * 
 * @return array<package\composer>
 */
public function find_installed_packages() {
	if ($this->installed === null) {
		if (debby\VERBOSE) {
			debby\debby::log('Checking '.$this->get_name().' for installed packages');
		}
		
		if (file_exists($this->path.'composer.lock') === false) {
			$e = new exception('can not find composer.lock in the path');
			$e->stop();
		}
		
		$composer_lock = file_get_contents($this->path.'composer.lock');
		$composer_lock = json_decode($composer_lock, true);
		if (empty($composer_lock['packages'])) {
			$e = new exception('lock file is missing its packages');
			$e->stop();
		}
		
		$this->installed = [];
		foreach ($composer_lock['packages'] as $package_info) {
			$package = $this->get_package_by_name($package_info['name']);
			$package->mark_installed($package_info['version']);
			
			if (strpos($package_info['version'], 'dev-') === 0) {
				$package->mark_installed_by_reference($package_info['source']['reference']);
			}
			
			$this->installed[$package->get_name()] = $package;
		}
	}
	
	return array_values($this->installed);
}

/**
 * give a list of all updatable packages
 * 
 * @note this uses the composer executable to check packages for newer versions than currently installed
 * 
 * @return array<package\composer>
 */
public function find_updatable_packages() {
	if ($this->updatable === null) {
		/**
		 * @todo don't call installed_packages here
		 *       instead, make calls to installed_version find out if there are installed
		 *       and let them cache the composer.lock themselves
		 */
		$installed_packages = $this->find_installed_packages();
		$required_packages  = $this->find_required_packages();
		
		if (debby\VERBOSE) {
			debby\debby::log('Checking '.$this->get_name().' for updatable packages');
		}
		
		if (debby\VERBOSE) {
			$debug_index   = 0;
			$debug_count   = count($required_packages);
			$debug_padding = strlen($debug_count);
		}
		
		$this->updatable = [];
		foreach ($required_packages as $package) {
			if (debby\VERBOSE) {
				$debug_index++;
				$debug_prefix = "\t".str_pad($debug_index, $debug_padding, $string=' ', $type=STR_PAD_LEFT).'/'.$debug_count.': ';
				debby\debby::log($debug_prefix.$package->get_name());
			}
			
			$version_regex = '/versions\s*:.+v?([0-9]+\.[0-9]+(\.[0-9]+)?)(,|$)/U';
			if ($package->is_installed_by_reference()) {
				$version_regex = '/source\s*:.+ ([a-f0-9]{40})$/m';
			}
			
			// find out the latest release
			$package_info = shell_exec('cd '.$this->path.' && '.$this->executable.' show -a '.escapeshellarg($package->get_name()));
			preg_match($version_regex, $package_info, $latest_version);
			if (empty($latest_version)) {
				$e = new exception('can not find out latest release for '.$package->get_name());
				$e->stop();
			}
			
			if ($package->is_later_version($latest_version[1])) {
				$package->mark_updatable($latest_version[1]);
				$this->updatable[$package->get_name()] = $package;
			}
		}
	}
	
	return array_values($this->updatable);
}

}
