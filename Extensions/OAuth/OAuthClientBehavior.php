<?php
namespace Quark\Extensions\OAuth;

/**
 * Trait OAuthClientBehavior
 *
 * @package Quark\Extensions\OAuth
 */
trait OAuthClientBehavior {
	/**
	 * @param string $config
	 * @param string $redirect = ''
	 * @param string[] $scope = []
	 *
	 * @return string|null
	 */
	public function OAuthLoginURL ($config = '', $redirect = '', $scope = []) {
		$token = new OAuthToken($config);

		return $token->Consumer()->OAuthLoginURL($redirect, $scope);
	}
}