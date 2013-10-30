<?php

namespace CyberSpectrum\Command;

use Composer\Autoload\ClassMapGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class BuildCommand extends Command
{
	/**
	 * @var InputInterface
	 */
	protected $input;

	/**
	 * @var OutputInterface
	 */
	protected $output;

	protected $repository;

	protected $package;

	protected $config;

	protected $encodedName;

	protected $nightlyModule;

	protected $runonce = array();

	protected $classmap = array();

	/**
	 * @var FileSystem $fs
	 */
	protected $fs;

	const OPENER = '
/**
 * THIS BLOCK HAS BEEN ADDED BY THE NIGHTLY PACKAGE BUILDER.
 * DO NOT REMOVE!
 */
';

	const CLOSER = '
/**
 * END OF NIGHTLY PACKAGE BUILDER ADDED BLOCK.
 */
';

	protected function configure()
	{
		$this
			->setName('build')
			->setDescription('Create packages from a composer designed repository including all dependencies for nightly distribution including custom auto loading etc.')
			->addOption('zip', 'Z', InputOption::VALUE_NONE, 'Create a zip archive (enabled by default, only present for sanity).')
			->addOption('dir', 'D', InputOption::VALUE_NONE, 'Create a directory instead of an archive.')
			->addOption('xml', 'x', InputOption::VALUE_OPTIONAL, 'Create a xml file at the given location.')
			->addArgument('project', InputArgument::OPTIONAL, 'The input path containing the composer.json.', 'nightly.composer.json')
			->addArgument('output', InputArgument::OPTIONAL, 'The output path.', 'package.zip');
	}

	/**
	 * @param Process $process
	 *
	 * @throws \RuntimeException
	 */
	protected function runProcess($process)
	{
		$output = $this->output;
		/** @noinspection PhpUnusedParameterInspection */
		$writethru = function ($type, $buffer) use ($output) {
			$output->write($buffer);
		};

		$process->setTimeout(120);
		$process->run($writethru);
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getErrorOutput());
		}
	}

	/**
	 * Get a copy of composer and install in workspace if not present already.
	 */
	protected function getComposer()
	{
		if (!file_exists($this->repository . '/composer.phar')) {
			$this->output->writeln('  - <info>Install local copy of composer</info>');
			$process = new Process('curl -sS https://getcomposer.org/installer | php', $this->repository);
			$this->runProcess($process);
		}
	}

	/**
	 * Prepare the workspace.
	 *
	 * @throws \RuntimeException
	 */
	protected function prepare()
	{
		$root         = getcwd();
		$this->config = json_decode(file_get_contents($this->input->getArgument('project')), true);

		if (!(isset($this->config['name']) && isset($this->config['require']))) {
			throw new \RuntimeException('Project ' . $this->input->getArgument('project') . ' does not seems to be a composer project.');
		}

		$this->encodedName   = str_replace('/', '-', $this->config['name']);
		$this->nightlyModule = 'system/modules/!' . $this->encodedName;

		$this->repository = $root . DIRECTORY_SEPARATOR . 'build_repository_' . $this->encodedName;
		if (!is_dir($this->repository))
			mkdir($this->repository);

		$this->package = $root . DIRECTORY_SEPARATOR . 'build_package_' . $this->encodedName;

		$this->fs->remove($this->package);
		$this->output->writeln('  - <info>Preparing clean output environment ' . $this->package . '</info>');

		if (!is_dir($this->package))
		{
			mkdir($this->package);
		}

		$this->output->writeln('  - <info>Prepare project ' . $this->repository . DIRECTORY_SEPARATOR . 'composer.json' . '</info>');
		copy($this->input->getArgument('project'), $this->repository . DIRECTORY_SEPARATOR . 'composer.json');

		$this->output->writeln('  - <info>Validate project</info>');
		if (!file_exists($this->repository . '/composer.json')) {
			throw new \RuntimeException('Project ' . $this->input->getArgument('project') . ' does not seems to be a composer project.');
		}

	}

	/**
	 * Perform the composer install/update to get the packages in the current version.
	 */
	protected function doInstall()
	{
		$this->output->writeln('  - <info>Install dependencies</info>');
		// $process = new Process('php ' . escapeshellarg($this->repository . '/composer.phar') . ' install --no-dev', $this->repository);
		$process = new Process('php ' . escapeshellarg($this->repository . '/composer.phar') . ' update --no-dev', $this->repository);
		$this->runProcess($process);
	}

	/**
	 * Determine if a package is blacklisted and therefore shall not get exported.
	 *
	 * @param string $package The package name as stated in composer.json (vendor/name).
	 *
	 * @return bool
	 */
	protected function isBlackListedPackage($package)
	{
		$blacklist = array('contao/core', 'contao-community-alliance/composer-installer');

		if (array_key_exists('extra', $this->config)
			&& array_key_exists('nightly-builder', $this->config['extra'])
			&& array_key_exists('blacklist', $this->config['extra']['nightly-builder'])) {
			$blacklist = array_merge($blacklist, $this->config['extra']['nightly-builder']['blacklist']);
		}

		return in_array($package, $blacklist);
	}

	/**
	 * Create the autoloader for the nightly build package.
	 *
	 * @param string $modulePath The module path to this nightly package.
	 *
	 * @param array  $classmap   The class map for all classes that are not loaded via auto loader within Contao but via composer.
	 */
	protected function createAutoloader($modulePath, $classmap)
	{
		if (!$classmap)
		{
			return;
		}

		$destPath = $this->package . '/' . $modulePath;

		if (!is_dir($destPath . '/config'))
		{
			mkdir($destPath . '/config', 0777, true);
		}

		if (file_exists($destPath . '/config/autoload.php')) {
			$autoload = file_get_contents($destPath . '/config/autoload.php');
			$autoload = preg_replace('#\?>\s*$#', '', $autoload);
		}
		else {
			$autoload = <<<EOF
<?php

EOF
			;
		}

		$opener = self::OPENER;
		$closer = self::CLOSER;

		$autoload .= <<<EOF
$opener
require_once(TL_ROOT . '/$modulePath/config/vendor_autoload.php');
$closer
EOF
		;
		file_put_contents(
			$destPath . '/config/autoload.php',
			$autoload
		);

		if (file_exists($destPath . '/config/config.php')) {
			$config = file_get_contents($destPath . '/config/config.php');
			$config = preg_replace('#\?>\s*$#', '', $config);
		}
		else {
			$config = <<<EOF
<?php

EOF
			;
		}

		$classmapClasses = array();
		foreach ($classmap as $className => $path) {
			$classmapClasses[] = $className;
		}
		$classmapClasses = array_map(
			function($className) {
				return var_export($className, true);
			},
			$classmapClasses
		);
		$classmapClasses = implode(',' . "\n\t\t", $classmapClasses);

		$config .= <<<EOF
$opener
if (version_compare(VERSION, '3', '<')) {
	spl_autoload_unregister('__autoload');
	require_once(TL_ROOT . '/$modulePath/config/vendor_autoload.php');
	spl_autoload_register('__autoload');

	\$classes = array(
		$classmapClasses
	);
	\$cache = FileCache::getInstance('classes');
	foreach (\$classes as \$class) {
		if (!\$cache->\$class) {
			\$cache->\$class = true;
		}
	}
}
$closer
EOF
		;
		file_put_contents(
			$destPath . '/config/config.php',
			$config
		);

		$classmapTmp = array();
		foreach ($classmap as $class => $file)
		{
			$classmapTmp[$class] = str_replace($this->package, '', $file);
		}

		$classmapExport = var_export($classmapTmp, true);
		$fn             = md5($this->encodedName);

		$function = <<<EOF
<?php
/**
 * This file has been auto generated by the nightly build script and is only needed for nightly builds.
 */

function autoload_$fn(\$className)
{
	\$classes =
$classmapExport;
	if (isset(\$classes[\$className]))
	{
		require_once(TL_ROOT . '/' . \$classes[\$className]);
	}
}

spl_autoload_register('autoload_$fn');
EOF
		;
		file_put_contents(
			$destPath . '/config/vendor_autoload.php',
			$function
		);
	}

	/**
	 * Determine if a string is a prefix of another one.
	 *
	 * @param string $prefix The prefix to test against.
	 *
	 * @param string $long   The long string that shall be tested if it starts with the given prefix.
	 *
	 * @return bool
	 */
	protected function isPrefixOf($prefix, $long)
	{
		//
		return substr($long, 0, strlen($prefix)) == $prefix;
	}

	/**
	 * Determine if at least one string within an array is prefixed with the given prefix.
	 *
	 * @param string $prefix The prefix to test against.
	 *
	 * @param array  $list   The list of strings to test.
	 *
	 * @return bool
	 */
	protected function isPrefixIn($prefix, $list)
	{
		foreach ($list as $long)
		{
			if ($this->isPrefixOf($prefix, $long))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Assemble all classes from a package that have not yet been copied to the module path.
	 * Within the destination path, a new sub directory named "classes" will get created and used as destination.
	 *
	 * @param array  $package    The package information as in composer.lock
	 *
	 * @param string $modulePath The module path to use as destination folder to place classes.
	 *
	 * @param array $blackList   An optional blacklist of filename prefixes that shall not get copied.
	 */
	protected function addAdditionalClasses($package, $modulePath, $blackList = array())
	{
		$classmapGenerator = new ClassMapGenerator();
		$classmap          = array();
		$srcPath           = $this->repository . '/vendor/' . $package['name'];
		$destPath          = $this->package . '/' . $modulePath;

		if (array_key_exists('autoload', $package)) {
			if (array_key_exists('psr-0', $package['autoload'])) {
				foreach ($package['autoload']['psr-0'] as $source) {
					if ($this->isPrefixIn($srcPath . '/' . $source, $blackList))
					{
						continue;
					}
					$this->copy($srcPath . '/' . $source, $destPath . '/classes/' . $source);
					$classmap = array_merge($classmap, $classmapGenerator->createMap($destPath . '/classes/' . $source));
				}
			}
			if (array_key_exists('classmap', $package['autoload'])) {
				foreach ($package['autoload']['classmap'] as $source) {
					if ($this->isPrefixIn($srcPath . '/' . $source, $blackList))
					{
						continue;
					}
					$this->copy($srcPath . '/' . $source, $destPath . '/classes/' . $source);
					$classmap = array_merge($classmap, $classmapGenerator->createMap($destPath . '/classes/' . $source));
				}
			}
		}
		foreach ($classmap as $class => $file)
		{
			$classmap[$class] = str_replace($this->package, '', $file);
		}

		$this->classmap += $classmap;

		return;
	}

	/**
	 * Collect all data for a Contao module and copy it to the appropriate location.
	 *
	 * @param array  $package    The package information as in composer.lock
	 */
	protected function assembleContaoModule($package)
	{
		$symlinks = array();
		$runonce  = array();

		if (array_key_exists('extra', $package) && array_key_exists('contao', $package['extra']))
		{
			if (array_key_exists('symlinks', $package['extra']['contao']))
			{
				$symlinks = $package['extra']['contao']['symlinks'];
			}
			if (array_key_exists('sources', $package['extra']['contao']))
			{
				$symlinks = array_merge($package['extra']['contao']['sources'], $symlinks);
			}

			if (array_key_exists('runonce', $package['extra']['contao'])) {
				$runonce = $package['extra']['contao']['runonce'];
			}
		}

		$blackList = array();
		foreach ($symlinks as $source => $target) {
			$this->copy($this->repository . '/vendor/' . $package['name'] . '/' . $source, $this->package . '/' . $target);
			$blackList[] = $this->repository . '/vendor/' . $package['name'] . '/' . $source;
		}

		$modulePath = 'system/modules/' . str_replace('/', '-', $package['name']);
		// Detect best possible place to put classes into.
		foreach ($symlinks as $target) {
			if (preg_match('#^system/modules/[^/]+#', $target)) {
				$modulePath = $target;
				break;
			}
		}

		$this->addAdditionalClasses($package, $modulePath, $blackList);

		$i = count($this->runonce);
		foreach (array_values($runonce) as $file) {
			$fileName = $this->package . '/' . $this->nightlyModule . '/config/runonce_' . ($i++) . '.php';

			$this->runonce[] = $fileName;
			$this->copy($this->repository . '/vendor/' . $package['name'] .'/' . $file, $fileName);
		}
	}

	/**
	 * Collect all data for a standard composer library and copy it to the appropriate location.
	 *
	 * @param array  $package    The package information as in composer.lock
	 */
	protected function assembleLibrary($package)
	{
		$this->addAdditionalClasses($package, 'system/modules/' . str_replace('/', '-', $package['name']));
	}

	/**
	 * Collect all (non blacklisted) installed packages into the destination path.
	 *
	 * @throws \RuntimeException
	 */
	protected function assemblePackages()
	{
		$config = json_decode(file_get_contents($this->repository . '/composer.lock'), true);
		$this->output->writeln('  - <info>importing: </info>');
		foreach ($config['packages'] as $package)
		{
			if (!$this->isBlackListedPackage($package['name']))
			{
				$this->output->writeln('    - <info>' . $package['name'] . '</info>');

				switch ($package['type'])
				{
					case 'metapackage':
						$this->output->writeln('    - skipped metapackage: <comment>' . $package['name'] . '</comment>');
						break;
					case 'contao-module':
						$this->assembleContaoModule($package);
						break;

					case 'library':
						$this->assembleLibrary($package);
						break;

					default:
						throw new \RuntimeException('Unknown module type encountered: ' . $package['type']);
				}
			}
			else
			{
				$this->output->writeln('    - skipped unneccessary: <comment>' . $package['name'] . '</comment>');
			}
		}
	}

	/**
	 * Save the runonce information into the destination path.
	 *
	 */
	protected function saveConfig()
	{
		if (count($this->runonce)) {
			$dirName = $this->package . '/' . $this->nightlyModule . '/config';
			if (!is_dir($dirName))
			{
				mkdir($dirName, 0777, true);
			}
			$class = 'runonce_' . md5(uniqid('runonce_', true));
			file_put_contents(
				$dirName . '/runonce.php',
				<<<EOF
<?php

class $class extends System
{
	public function __construct()
	{
		parent::__construct();
	}

	public function run()
	{
		for (\$i=0; file_exists(__DIR__ . '/runonce_' . \$i . '.php'); \$i++) {
			try {
				require_once(__DIR__ . '/runonce_' . \$i . '.php');
			}
			catch (\Exception \$e) {
				// first trigger an error to write this into the log file
				trigger_error(
					\$e->getMessage() . "\n" . \$e->getTraceAsString(),
					E_USER_ERROR
				);
				// now log into the system log
				\$this->log(
					\$e->getMessage() . "\n" . \$e->getTraceAsString(),
					'RunonceExecutor run()',
					'ERROR'
				);
			}
		}
	}
}

\$executor = new $class();
\$executor->run();

EOF
			);
		}
	}

	protected function getLicenseUrl($license)
	{
		if (in_array($license, array(
			'AFL-1.1', 'AFL-1.2', 'AFL-2.0', 'AFL-2.1', 'AFL-3.0', 'APL-1.0', 'Aladdin', 'ANTLR-PD', 'Apache-1.0',
			'Apache-1.1', 'Apache-2.0', 'APSL-1.0', 'APSL-1.1', 'APSL-1.2', 'APSL-2.0', 'Artistic-1.0',
			'Artistic-1.0-cl8', 'Artistic-1.0-Perl', 'Artistic-2.0', 'AAL', 'BitTorrent-1.0', 'BitTorrent-1.1',
			'BSL-1.0', 'BSD-2-Clause', 'BSD-2-Clause-FreeBSD', 'BSD-2-Clause-NetBSD', 'BSD-3-Clause',
			'BSD-3-Clause-Clear', 'BSD-4-Clause', 'BSD-4-Clause-UC', 'CECILL-1.0', 'CECILL-1.1', 'CECILL-2.0',
			'CECILL-B', 'CECILL-C', 'ClArtistic', 'CNRI-Python', 'CNRI-Python-GPL-Compatible', 'CPOL-1.02',
			'CDDL-1.0', 'CDDL-1.1', 'CPAL-1.0', 'CPL-1.0', 'CATOSL-1.1', 'Condor-1.1', 'CC-BY-1.0', 'CC-BY-2.0',
			'CC-BY-2.5', 'CC-BY-3.0', 'CC-BY-ND-1.0', 'CC-BY-ND-2.0', 'CC-BY-ND-2.5', 'CC-BY-ND-3.0',
			'CC-BY-NC-1.0', 'CC-BY-NC-2.0', 'CC-BY-NC-2.5', 'CC-BY-NC-3.0', 'CC-BY-NC-ND-1.0', 'CC-BY-NC-ND-2.0',
			'CC-BY-NC-ND-2.5', 'CC-BY-NC-ND-3.0', 'CC-BY-NC-SA-1.0', 'CC-BY-NC-SA-2.0', 'CC-BY-NC-SA-2.5',
			'CC-BY-NC-SA-3.0', 'CC-BY-SA-1.0', 'CC-BY-SA-2.0', 'CC-BY-SA-2.5', 'CC-BY-SA-3.0', 'CC0-1.0',
			'CUA-OPL-1.0', 'D-FSL-1.0', 'WTFPL', 'EPL-1.0', 'eCos-2.0', 'ECL-1.0', 'ECL-2.0', 'EFL-1.0', 'EFL-2.0',
			'Entessa', 'ErlPL-1.1', 'EUDatagrid', 'EUPL-1.0', 'EUPL-1.1', 'Fair', 'Frameworx-1.0', 'FTL',
			'AGPL-1.0', 'AGPL-3.0', 'GFDL-1.1', 'GFDL-1.2', 'GFDL-1.3', 'GPL-1.0', 'GPL-1.0+', 'GPL-2.0',
			'GPL-2.0+', 'GPL-2.0-with-autoconf-exception', 'GPL-2.0-with-bison-exception',
			'GPL-2.0-with-classpath-exception', 'GPL-2.0-with-font-exception', 'GPL-2.0-with-GCC-exception',
			'GPL-3.0', 'GPL-3.0+', 'GPL-3.0-with-autoconf-exception', 'GPL-3.0-with-GCC-exception', 'LGPL-2.1',
			'LGPL-2.1+', 'LGPL-3.0', 'LGPL-3.0+', 'LGPL-2.0', 'LGPL-2.0+', 'gSOAP-1.3b', 'HPND', 'IBM-pibs',
			'IPL-1.0', 'Imlib2', 'IJG', 'Intel', 'IPA', 'ISC', 'JSON', 'LPPL-1.3a', 'LPPL-1.0', 'LPPL-1.1',
			'LPPL-1.2', 'LPPL-1.3c', 'Libpng', 'LPL-1.02', 'LPL-1.0', 'MS-PL', 'MS-RL', 'MirOS', 'MIT', 'Motosoto',
			'MPL-1.0', 'MPL-1.1', 'MPL-2.0', 'MPL-2.0-no-copyleft-exception', 'Multics', 'NASA-1.3', 'Naumen',
			'NBPL-1.0', 'NGPL', 'NOSL', 'NPL-1.0', 'NPL-1.1', 'Nokia', 'NPOSL-3.0', 'NTP', 'OCLC-2.0', 'ODbL-1.0',
			'PDDL-1.0', 'OGTSL', 'OLDAP-2.2.2', 'OLDAP-1.1', 'OLDAP-1.2', 'OLDAP-1.3', 'OLDAP-1.4', 'OLDAP-2.0',
			'OLDAP-2.0.1', 'OLDAP-2.1', 'OLDAP-2.2', 'OLDAP-2.2.1', 'OLDAP-2.3', 'OLDAP-2.4', 'OLDAP-2.5',
			'OLDAP-2.6', 'OLDAP-2.7', 'OPL-1.0', 'OSL-1.0', 'OSL-2.0', 'OSL-2.1', 'OSL-3.0', 'OLDAP-2.8', 'OpenSSL',
			'PHP-3.0', 'PHP-3.01', 'PostgreSQL', 'Python-2.0', 'QPL-1.0', 'RPSL-1.0', 'RPL-1.1', 'RPL-1.5',
			'RHeCos-1.1', 'RSCPL', 'Ruby', 'SAX-PD', 'SGI-B-1.0', 'SGI-B-1.1', 'SGI-B-2.0', 'OFL-1.0', 'OFL-1.1',
			'SimPL-2.0', 'Sleepycat', 'SMLNJ', 'SugarCRM-1.1.3', 'SISSL', 'SISSL-1.2', 'SPL-1.0', 'Watcom-1.0',
			'NCSA', 'VSL-1.0', 'W3C', 'WXwindows', 'Xnet', 'X11', 'XFree86-1.1', 'YPL-1.0', 'YPL-1.1', 'Zimbra-1.3',
			'Zlib', 'ZPL-1.1', 'ZPL-2.0', 'ZPL-2.1', 'Unlicense')))
		{
			return sprintf('http://spdx.org/licenses/%1$s', $license);
		}
		else
		{
			return $license;
		}
	}

	protected function strIsLongerThan($a, $b)
	{
		if (strlen($a) > strlen($b))
		{
			return true;
		}

		return false;
	}

	protected function preparePackageInformation()
	{
		$lock = json_decode(file_get_contents($this->repository . '/composer.lock'), true);

		$data = array
		(
			'maxlen' => array(
				'name'       => '',
				'url'        => '',
				'link'       => '',
				'version'    => '',
				'lastchange' => '',
				'license'    => array
				(
					'name'   => '',
					'url'    => ''
				)
			)
		);

		foreach ($lock['packages'] as $package) {

			if ($this->isBlackListedPackage($package['name']))
			{
				continue;
			}

			if (isset($package['source'])) {
				$url = preg_replace('#\.git$#', '', $package['source']['url']);
			}
			else
			{
				$url = false;
			}

			if (isset($package['homepage'])) {
				$homepage = $package['homepage'];
			}
			else
			{
				$homepage = false;
			}

			$version = $package['version'];
			$time    = $package['time'];

			if (preg_match('#(^dev-|-dev$)#', $package['version'])) {
				if (isset($package['source']['reference'])) {
					$version .= ' @ ' . substr($package['source']['reference'], 0, 6);
				}
				else if (isset($package['dist']['reference'])) {
					$version .= ' @ ' . substr($package['dist']['reference'], 0, 6);
				}
			}

			$license_urls = array();
			foreach ($package['license'] as $license)
			{
				$license_url = $this->getLicenseUrl($license);
				if ($license_url !== $package['license'])
				{
					$license_urls[] = array(
						'name' => $license,
						'url'  => $license_url
					);
				}
				else
				{
					$license_urls[] = array(
						'name' => $license,
						'url'  => ''
					);
				}
			}

			$data[$package['name']] = array(
				'name'       => $package['name'],
				'homepage'   => $homepage,
				'url'        => $url,
				'license'    => $license_urls,
				'version'    => $version,
				'lastchange' => $time
			);

			if ($this->strIsLongerThan($package['name'], $data['maxlen']['name']))
			{
				$data['maxlen']['name'] = $package['name'];
			}
			if ($this->strIsLongerThan($url, $data['maxlen']['url']))
			{
				$data['maxlen']['url'] = $url;
			}

			foreach ($license_urls as $license)
			{
				if ($this->strIsLongerThan($license['name'], $data['maxlen']['license']['name']))
				{
					$data['maxlen']['license']['name'] = $license['name'];
				}
				if ($this->strIsLongerThan($license['url'], $data['maxlen']['license']['url']))
				{
					$data['maxlen']['license']['url'] = $license['url'];
				}
			}

			if ($this->strIsLongerThan($version, $data['maxlen']['version']))
			{
				$data['maxlen']['version'] = $version;
			}
			if ($this->strIsLongerThan($time, $data['maxlen']['lastchange']))
			{
				$data['maxlen']['lastchange'] = $time;
			}
		}

		return $data;
	}

	/**
	 * Create the backend module which displays information about this build.
	 */
	protected function createBackendModule()
	{
		$dirName   = $this->package . '/' . $this->nightlyModule;
		$modPath   = $this->nightlyModule;
		$baseUrl   = '\Environment::getInstance()->base . \'/' . $modPath . '/html/github-btn.html\'';

		$beName    = $this->encodedName;
		$beSection = 'Nightly builds';

		if (isset($this->config['extra']['nightly-builder']['backend-name']))
		{
			$beName = $this->config['extra']['nightly-builder']['backend-name'];
		}
		if (isset($this->config['extra']['nightly-builder']['backend-section']))
		{
			$beSection = $this->config['extra']['nightly-builder']['backend-section'];
		}


		$className = 'BackendModule_' . md5($this->encodedName);

		$html = <<<EOF
<div class="tl_message"><table class="table table-striped nightlyinfo">
	<colgroup>
		<col width="200" />
		<col width="150" />
		<col width="90" />
		<col width="90" />
		<col width="160" />
	</colgroup>
	<thead>
		<tr>
			<th class="name">Package</th>
			<th class="version">Version</th>
			<th class="time">Time</th>
			<th class="license">License</th>
			<th class="buttons">Github</th>
		</tr>
	</thead>
	<tbody>

EOF;

		foreach ($this->preparePackageInformation() as $name => $package)
		{
			if ($name == 'maxlen')
			{
				continue;
			}

			if (preg_match('#^https?://#', $package['url'])) {
				$link = sprintf('<a href="%1$s" target="_blank">%2$s</a>', $package['url'], $package['name']);
			}
			else {
				$link = $package['name'];
			}

			$version = str_replace(' ', '&nbsp;', $package['version']);

			$time    = $package['lastchange'];

			$license_link = '';
			foreach ($package['license'] as $license)
			{
				if ($license['url']) {
					$license_link .= sprintf('<a href="%1$s" target="_blank">%2$s</a>', $license['url'], $license['name']);
				}
				else
				{
					$license_link .= $license['name'];
				}
			}

			if (preg_match('#^https?://github.com/([^/]+)/([^/]+)#', $package['url'], $matches))
			{
				$buttons = sprintf('
				<iframe src="%1$s?user=%2$s&repo=%3$s&type=watch&count=true" allowtransparency="true" frameborder="0" scrolling="0" width="110" height="20"></iframe>
				<iframe src="%1$s?user=%2$s&repo=%3$s&type=fork&count=true" allowtransparency="true" frameborder="0" scrolling="0" width="95" height="20"></iframe>
',
					'\' . $baseUrl . \'',
					$matches[1],
					$matches[2]
				);
			}
			else
			{
				$buttons = '';
			}

			$html .= <<<EOF
		<tr>
			<td class="name">{$link}</td>
			<td class="version">{$version}</td>
			<td class="time">{$time}</td>
			<td class="license">{$license_link}</td>
			<td class="buttons">{$buttons}</td>
		</tr>

EOF;
		}
		$html .= <<<EOF
	</tbody>
</table></div>

EOF;

		if (!is_dir($dirName))
		{
			mkdir($dirName . '/config', 0777, true);
			mkdir($dirName . '/html', 0777, true);
		}

		file_put_contents(
			$dirName . '/config/config.php',
			<<<EOF
<?php
\$GLOBALS['TL_LANG']['MOD']['$className'] = array('$beName');
\$GLOBALS['BE_MOD']['$beSection']['$className'] = array('callback' => '$className');
EOF
		);

		$this->classmap[$className] = $dirName . '/' . $className . '.php';

		file_put_contents(
			$dirName . '/' . $className . '.php',
			<<<EOF
<?php
/**
 * Auto generated summary class for nightly build.
 *
 * See https://github.com/discordier/nightly-builder to learn how it works.
 */
class $className extends \BackendModule
{
	/**
	 * Display the overview.
	 */
	public function generate()
	{
		\$GLOBALS['TL_CSS'][] = '$modPath/html/style.css';
		\$baseUrl = $baseUrl;
		return '$html';
	}

	// No-Op to make the class non abstract.
	protected function compile(){}
}
EOF
		);

		file_put_contents(
			$dirName . '/html/.htaccess',
			<<<EOF
<IfModule mod_authz_core.c>
    Require all granted
</IfModule>
<IfModule !mod_authz_core.c>
    Order Deny,Allow
    Allow from all
</IfModule>
EOF
		);

		file_put_contents(
			$dirName . '/html/style.css',
			str_replace(array('  ', "\n"), '', <<<EOF
  table {
    border-top:1px solid #d6d6d6;
    border-left:1px solid #d6d6d6;
    width: 100%;
    margin-bottom:18px;
  }

  th {
    text-align:left;
    background-color:#d6d6d6;
    padding:1px 6px 3px;
  }

  td {
    border-right:1px solid #d6d6d6;
    border-bottom:1px solid #d6d6d6;
    padding:3px 6px;
  }

  tr:nth-child(even) {
    background:#ebfdd7;
  }

  tr:nth-child(odd) {
    background:#fff;
  }
EOF
			)
		);

		file_put_contents(
			$dirName . '/html/github-btn.html',
			<<<EOF
<html><body><style type="text/css">body{padding:0;margin:0;font:bold 11px/14px "Helvetica Neue",Helvetica,Arial,sans-serif;text-rendering:optimizeLegibility;overflow:hidden}.github-btn{height:20px;overflow:hidden}.gh-btn,.gh-count,.gh-ico{float:left}.gh-btn,.gh-count{padding:2px 5px 2px 4px;color:#555;text-decoration:none;text-shadow:0 1px 0 #fff;white-space:nowrap;cursor:pointer;border-radius:3px}.gh-btn{background-color:#e6e6e6;background-image:-webkit-gradient(linear,0 0,0 100%,from(#fafafa),to(#eaeaea));background-image:-webkit-linear-gradient(#fafafa,#eaeaea);background-image:-moz-linear-gradient(top,#fafafa,#eaeaea);background-image:-ms-linear-gradient(#fafafa,#eaeaea);background-image:-o-linear-gradient(#fafafa,#eaeaea);background-image:linear-gradient(#fafafa,#eaeaea);background-repeat:no-repeat;border:1px solid #d4d4d4;border-bottom-color:#bcbcbc}.gh-btn:hover,.gh-btn:focus,.gh-btn:active{color:#fff;text-decoration:none;text-shadow:0 -1px 0 rgba(0,0,0,.25);border-color:#518cc6 #518cc6 #2a65a0;background-color:#3072b3}.gh-btn:hover,.gh-btn:focus{background-image:-webkit-gradient(linear,0 0,0 100%,from(#599bdc),to(#3072b3));background-image:-webkit-linear-gradient(#599bdc,#3072b3);background-image:-moz-linear-gradient(top,#599bdc,#3072b3);background-image:-ms-linear-gradient(#599bdc,#3072b3);background-image:-o-linear-gradient(#599bdc,#3072b3);background-image:linear-gradient(#599bdc,#3072b3)}.gh-btn:active{background-image:none;-webkit-box-shadow:inset 0 2px 5px rgba(0,0,0,.10);-moz-box-shadow:inset 0 2px 5px rgba(0,0,0,.10);box-shadow:inset 0 2px 5px rgba(0,0,0,.10)}.gh-ico{width:14px;height:15px;margin-top:-1px;margin-right:4px;vertical-align:middle;background-image:url(data:image/png;base64,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);background-repeat:no-repeat;background-position:0 0}.gh-btn:hover .gh-ico,.gh-btn:focus .gh-ico,.gh-btn:active .gh-ico{background-position:-25px 0}.gh-count{position:relative;display:none;margin-left:4px;background-color:#fafafa;border:1px solid #d4d4d4}.gh-count:hover,.gh-count:focus{color:#4183c4}.gh-count:before,.gh-count:after{content:' ';position:absolute;display:inline-block;width:0;height:0;border-color:transparent;border-style:solid}.gh-count:before{top:50%;left:-3px;margin-top:-4px;border-width:4px 4px 4px 0;border-right-color:#fafafa}.gh-count:after{top:50%;left:-4px;z-index:-1;margin-top:-5px;border-width:5px 5px 5px 0;border-right-color:#d4d4d4}.github-btn-large{height:30px}.github-btn-large .gh-btn,.github-btn-large .gh-count{padding:3px 10px 3px 8px;font-size:16px;line-height:22px;border-radius:4px}.github-btn-large .gh-ico{width:22px;height:23px;background-position:0 -20px}.github-btn-large .gh-btn:hover .gh-ico,.github-btn-large .gh-btn:focus .gh-ico,.github-btn-large .gh-btn:active .gh-ico{background-position:-25px -20px}.github-btn-large .gh-count{margin-left:6px}.github-btn-large .gh-count:before{left:-5px;margin-top:-6px;border-width:6px 6px 6px 0}.github-btn-large .gh-count:after{left:-6px;margin-top:-7px;border-width:7px 7px 7px 0}@media(-moz-min-device-pixel-ratio:2),(-o-min-device-pixel-ratio:2/1),(-webkit-min-device-pixel-ratio:2),(min-device-pixel-ratio:2){.gh-ico{background-image:url(data:image/png;base64,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);background-size:50px 45px}}</style> <span class=github-btn id=github-btn> <a class=gh-btn id=gh-btn href="#" target=_blank> <span class=gh-ico></span> <span class=gh-text id=gh-text></span> </a> <a class=gh-count id=gh-count href="#" target=_blank></a> </span> <script type="text/javascript">var params=function(){var d=[],c;var a=window.location.href.slice(window.location.href.indexOf("?")+1).split("&");for(var b=0;b<a.length;b++){c=a[b].split("=");d.push(c[0]);d[c[0]]=c[1]}return d}();var user=params.user,repo=params.repo,type=params.type,count=params.count,size=params.size,head=document.getElementsByTagName("head")[0],button=document.getElementById("gh-btn"),mainButton=document.getElementById("github-btn"),text=document.getElementById("gh-text"),counter=document.getElementById("gh-count");function addCommas(a){return String(a).replace(/(\d)(?=(\d{3})+$)/g,"$1,")}function jsonp(b){var a=document.createElement("script");a.src=b+"?callback=callback";head.insertBefore(a,head.firstChild)}function callback(a){if(type=="watch"){counter.innerHTML=addCommas(a.data.watchers)}else{if(type=="fork"){counter.innerHTML=addCommas(a.data.forks)}else{if(type=="follow"){counter.innerHTML=addCommas(a.data.followers)}}}if(count=="true"){counter.style.display="block"}}button.href="https://github.com/"+user+"/"+repo+"/";if(type=="watch"){mainButton.className+=" github-watchers";text.innerHTML="Star";counter.href="https://github.com/"+user+"/"+repo+"/stargazers"}else{if(type=="fork"){mainButton.className+=" github-forks";text.innerHTML="Fork";counter.href="https://github.com/"+user+"/"+repo+"/network"}else{if(type=="follow"){mainButton.className+=" github-me";text.innerHTML="Follow @"+user;button.href="https://github.com/"+user;counter.href="https://github.com/"+user+"/followers"}}}if(size=="large"){mainButton.className+=" github-btn-large"}if(type=="follow"){jsonp("https://api.github.com/users/"+user)}else{jsonp("https://api.github.com/repos/"+user+"/"+repo)};</script></body></html>
EOF
		);

	}

	protected function createNightlyTxt()
	{
		$lines = array();

		$data = $this->preparePackageInformation();
		$max  = $data['maxlen'];

		foreach ($data as $name => $package)
		{
			if ($name == 'maxlen')
			{
				continue;
			}

			$line = sprintf('%s %s %s',
				str_pad($name, strlen($max['name'])),
				str_pad($package['version'], strlen($max['version'])),
				str_pad($package['lastchange'], strlen($max['lastchange']))
			);

			if ($package['homepage'])
			{
				$lines[$package['homepage']][] = $line;
			}
			else
			{
				$lines['other'][] = $line;
			}
		}

		$lineLen = strlen($max['name']) + strlen($max['version']) +  strlen($max['lastchange']) + 2;

		$text = trim(sprintf('%s %s %s',
			str_pad('Name', strlen($max['name'])),
			str_pad('Version', strlen($max['version'])),
			str_pad('Last modification', strlen($max['lastchange'])))
		). "\n";

		$text .= str_repeat('=', $lineLen) . "\n";

		foreach ($lines as $url => $packages)
		{
			if ($url == 'other')
			{
				continue;
			}

			$text .= $url . "\n";
			$text .= implode("\n", $packages);
			$text .= "\n";
			$text .= str_repeat('-', $lineLen);
			$text .= "\n\n";
		}

		$text .= 'other' . "\n";
		$text .= implode("\n", $lines['other']);
		$text .= "\n\n";

		file_put_contents(
			$this->package . '/nightly.txt',
			$text
		);
	}

	protected function createNightlyXml()
	{
		$filename = $this->input->getOption('xml');

		if (!$filename)
		{
			return;
		}

		$data = $this->preparePackageInformation();

		$text = '<versioninfo>
';

		foreach ($data as $name => $package)
		{
			if ($name == 'maxlen')
			{
				continue;
			}

			$text .= sprintf(
				'	<extension>
		<name>%s</name>
		<maintainer>%s</maintainer>
		<hash>%s</hash>
		<lastchange>%s</lastchange>
	</extension>
',
				$name,
				$package['homepage'] ? $package['homepage'] : 'other',
				$package['version'],
				$package['lastchange']
			);
		}

		$text .= '</versioninfo>
';

		file_put_contents(
			$filename,
			$text
		);
	}



	/**
	 * Deploy the contents of the build directory either into an archive or to the deploy path
	 * (depending on command line parameter).
	 *
	 */
	protected function deploy()
	{
		$output = $this->input->getArgument('output');
		if ($output == 'package.zip')
		{
			if ($this->input->getOption('dir'))
			{
				$output = $this->encodedName;
			}
			else
			{
				$output = $this->encodedName . '.zip';
			}
		}

		if ($this->input->getOption('dir')) {
			$this->output->writeln('  - <info>Create package ' . $output . '</info>');
			$this->copy($this->package, $output, new Filesystem());
		}
		else {
			$this->output->writeln('  - <info>Create package archive' . $output . '</info>');
			$zip = new \ZipArchive();
			$zip->open($output, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
			$this->addToZipArchive($zip, $this->package, '');
			$zip->close();
		}
	}

	/**
	 * Clean the build space.
	 */
	protected function cleanup()
	{
		$this->output->writeln('  - <info>Cleanup</info>');
//		$this->fs->remove($this->repository);
		$this->fs->remove($this->package);
	}

	/**
	 * Executes the command.
	 *
	 * @param InputInterface  $input  An InputInterface instance
	 *
	 * @param OutputInterface $output An OutputInterface instance
	 *
	 * @return null|integer null or 0 if everything went fine, or an error code
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->input  = $input;
		$this->output = $output;
		$this->fs     = new Filesystem();

		$this->prepare();
		$this->getComposer();
		$this->doInstall();
		$this->createBackendModule();
		$this->assemblePackages();
		$this->createAutoloader($this->nightlyModule, $this->classmap);
		$this->saveConfig();
		$this->createNightlyTxt();
		$this->createNightlyXml();
		$this->deploy();
//		$this->cleanup();
	}

	/**
	 * Recursive copy of a file/folder to another location.
	 *
	 * This method uses absolute pathes.
	 *
	 * @param string $source The source to copy.
	 *
	 * @param string $target The destination where to place the files.
	 */
	protected function copy($source, $target)
	{
		if (is_dir($source)) {
			$this->fs->mkdir($target);
			$iterator = new \FilesystemIterator($source, \FilesystemIterator::CURRENT_AS_PATHNAME);
			foreach ($iterator as $item) {
				$this->copy($item, $target . '/' . basename($item));
			}
		}
		else {
			$this->fs->copy($source, $target);
		}
	}

	/**
	 * Determine the type of a composer package by calling composer and examining the result.
	 *
	 * @param string $package The name of the package.
	 *
	 * @param string $version The version of the package.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException
	 */
	protected function getPackageType($package, $version)
	{
		$process = new Process('php ' . escapeshellarg($this->repository . '/composer.phar') . ' show ' . escapeshellarg($package) . ' ' . escapeshellarg($version));
		$process->setTimeout(120);
		$process->run();
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getErrorOutput());
		}
		$details = $process->getOutput();
		foreach (explode("\n", $details) as $line) {
			$parts = explode(':', $line);
			$parts = array_map('trim', $parts);
			if ($parts[0] == 'type') {
				return $parts[1];
			}
		}
		return 'library';
	}

	/**
	 * Recursively files/folders to the given zip file.
	 *
	 * @param \ZipArchive $zip    The archive to which the files shall get added to.
	 *
	 * @param string      $source The source path to add.
	 *
	 * @param string      $target The destination path where the files shall get placed to.
	 *
	 */
	protected function addToZipArchive(\ZipArchive $zip, $source, $target)
	{
		if (is_dir($source)) {
			if ($target) {
				$zip->addEmptyDir($target);
			}
			$iterator = new \FilesystemIterator($source, \FilesystemIterator::CURRENT_AS_PATHNAME);
			foreach ($iterator as $item) {
				$this->addToZipArchive($zip, $item, ($target ? $target . '/' : '') . basename($item));
			}
		}
		else {
			$zip->addFile($source, $target);
		}
	}
}
