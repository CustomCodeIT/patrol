<?php
namespace craft\plugins\patrol\services;

use Craft;
use craft\app\base\Component;
use craft\app\errors\Exception;
use craft\plugins\patrol\Patrol;
use craft\plugins\patrol\models\Settings;

/**
 * Class DefaultService
 *
 * @package craft\plugins\patrol\services
 */
class DefaultService extends Component
{
	/**
	 * @var Settings
	 */
	protected $settings;

	/**
	 * An array of key/value pairs used when parsing restricted areas like {cpTrigger}
	 *
	 * @var array
	 */
	protected $dynamicParams;

	public function watch()
	{
		$this->settings = Patrol::getInstance()->getSettings();

		$this->handleSslRouting();
		$this->handleMaintenanceMode();
	}

	/**
	 * Forces SSL based on restricted URLs
	 * The environment settings take priority over those defined in the control panel
	 *
	 * @return bool
	 */
	public function handleSslRouting()
	{
		if ($this->settings->sslRoutingEnabled)
		{
			$requestedUrl   = Craft::$app->request->getUrl();
			$restrictedUrls = $this->settings->sslRoutingRestrictedUrls;

			if (!Craft::$app->request->isSecureConnection)
			{

				foreach ($restrictedUrls as $restrictedUrl)
				{
					// Parse dynamic variables like /{cpTrigger}
					if (stripos($restrictedUrl, '{') !== false)
					{
						$restrictedUrl = Craft::$app->view->renderObjectTemplate($restrictedUrl, $this->getDynamicParams());
					}

					$restrictedUrl = '/'.ltrim($restrictedUrl, '/');

					if (stripos($requestedUrl, $restrictedUrl) === 0)
					{
						$this->forceSsl();
					}
				}

				return true;
			}
		}

		return false;
	}

	/**
	 * Restricts accessed based on authorizedIps
	 *
	 * @return bool
	 */
	public function handleMaintenanceMode()
	{
		// Authorize logged in admins on the fly
		if ($this->doesCurrentUserHaveAccess())
		{
			return true;
		}

		if (Craft::$app->request->isSiteRequest && $this->settings->maintenanceModeEnabled)
		{
			$requestingIp   = $this->getRequestingIp();
			$authorizedIps  = $this->settings->maintenanceModeAuthorizedIps;
			$maintenanceUrl = $this->settings->maintenanceModePageUrl;

			if ($maintenanceUrl == Craft::$app->request->getUrl())
			{
				return true;
			}

			if (empty($authorizedIps))
			{
				$this->forceRedirect($maintenanceUrl);
			}

			if (is_array($authorizedIps) && count($authorizedIps))
			{
				if (in_array($requestingIp, $authorizedIps))
				{
					return true;
				}

				foreach ($authorizedIps as $authorizedIp)
				{
					$authorizedIp = str_replace('*', '', $authorizedIp);

					if (stripos($requestingIp, $authorizedIp) === 0)
					{
						return true;
					}
				}

				$this->forceRedirect($maintenanceUrl);
			}
		}
	}

	/**
	 * Redirects to the HTTPS version of the requested URL
	 */
	protected function forceSsl()
	{
		$baseUrl = Craft::$app->view->renderObjectTemplate($this->settings->sslRoutingBaseUrl, $this->getDynamicParams());
		$baseUrl = trim($baseUrl);

		if (empty($baseUrl) || $baseUrl == '/')
		{
			$baseUrl = Craft::$app->request->getServerName();
		}

		$url = sprintf('https://%s%s', $baseUrl, Craft::$app->request->getUrl());

		if (!filter_var($url, FILTER_VALIDATE_URL))
		{
			throw new Exception(Patrol::t('{url} is not a valid URL', ['url' => $url]));
		}

		Craft::$app->response->redirect($url);
	}

	/**
	 * Returns a list of dynamic parameters and their values that can be used in restricted area settings
	 *
	 * @return array
	 */
	protected function getDynamicParams()
	{
		if (is_null($this->dynamicParams))
		{
			$variables           = Craft::$app->config->get('environmentVariables');
			$this->dynamicParams = array(
				'siteUrl'       => Craft::$app->config->parseEnvironmentString('siteUrl'),
				'cpTrigger'     => Craft::$app->config->get('cpTrigger'),
				'actionTrigger' => Craft::$app->config->get('actionTrigger')
			);

			if (is_array($variables) && count($variables))
			{
				$this->dynamicParams = array_merge($this->dynamicParams, $variables);
			}
		}

		return $this->dynamicParams;
	}

	/**
	 * Parses authorizedIps to ensure they are valid even when created from a string
	 *
	 * @param array|string $ips
	 *
	 * @return array
	 */
	public function parseAuthorizedIps($ips)
	{
		$ips = trim($ips);

		if (is_string($ips) && !empty($ips))
		{
			$ips = explode(PHP_EOL, $ips);
		}

		return $this->filterOutArrayValues(
			$ips, function ($val)
		{
			return preg_match('/^[0-9\.\*]{5,15}$/i', $val);
		}
		);
	}

	/**
	 * Parser restricted areas to ensure they are valid even when created from a string
	 *
	 * @param array|string $areas
	 *
	 * @return array
	 */
	public function parseRestrictedAreas($areas)
	{
		if (is_string($areas) && !empty($areas))
		{
			$areas = trim($areas);
			$areas = explode(PHP_EOL, $areas);
		}

		return $this->filterOutArrayValues(
			$areas, function ($val)
		{
			$valid = preg_match('/^[\/\{\}a-z\_\-\?\=]{1,255}$/i', $val);

			if (!$valid)
			{
				return false;
			}

			return true;
		}
		);
	}

	/**
	 * Filters out array values by using a custom filter
	 *
	 * @param array|string|null $values
	 * @param callable|\Closure $filter
	 * @param bool              $preserveKeys
	 *
	 * @return array
	 */
	protected function filterOutArrayValues($values = null, \Closure $filter = null, $preserveKeys = false)
	{
		$data = array();

		if (is_array($values) && count($values))
		{
			foreach ($values as $key => $value)
			{
				$value = trim($value);

				if (!empty($value))
				{
					if (is_callable($filter) && $filter($value))
					{
						$data[$key] = $value;
					}
				}
			}

			if (!$preserveKeys)
			{
				$data = array_values($data);
			}
		}

		return $data;
	}

	/**
	 * @param string $redirectTo
	 *
	 * @throws \HttpException
	 */
	protected function forceRedirect($redirectTo = '')
	{
		if (empty($redirectTo))
		{
			$this->runDefaultBehavior();
		}

		Craft::$app->response->redirect($redirectTo);
	}

	/**
	 * Ensures that we get the right IP address even if behind CloudFlare
	 *
	 * @return string
	 */
	public function getRequestingIp()
	{
		return isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : $_SERVER['REMOTE_ADDR'];
	}

	/**
	 * Returns whether or not the current user has access during maintenance mode
	 */
	protected function doesCurrentUserHaveAccess()
	{
		// Admins have access by default
		if (Craft::$app->user->getIsAdmin())
		{
			return true;
		}

		// User has the right permission
		if (Craft::$app->user->checkPermission(Patrol::MAINTENANCE_MODE_BYPASS_PERMISSION))
		{
			return true;
		}

		return false;
	}

	/**
	 * @throws \HttpException
	 */
	protected function runDefaultBehavior()
	{
		throw new \HttpException(403);
	}
}
