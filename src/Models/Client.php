<?php

namespace JakubKlapka\LaravelSharepointUploadClient\Models;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Psr\Http\Message\StreamInterface;

class Client {

	/**
	 * @var string
	 */
	protected $site_url;

	/**
	 * @var string
	 */
	protected $app_id;

	/**
	 * @var string
	 */
	protected $app_secret;

	/**
	 * @var string
	 */
	protected $redirect_uri;

    /**
     * @var string
     */
    protected $doc_library = 'Shared Documents';

	/**
	 * @var Application
	 */
	protected $app;

	public function __construct( $site_url, $app_id, $app_secret, $redirect_uri ) {
		$this->site_url = $site_url;
		$this->app_id = $app_id;
		$this->app_secret = $app_secret;
		$this->redirect_uri = $redirect_uri;
		$this->app = Application::getInstance();
	}

    public function setDocLibrary($doc_library)
    {
        $this->doc_library = $doc_library;
    }

	/**
	 * Construct URL, which will request for user consent with List.Write scope
	 *
	 * @return string
	 */
	public function getUserConsentUri() {

		$url = "{$this->site_url}/_layouts/15/oauthauthorize.aspx?client_id={$this->app_id}".
		       "&response_type=code&scope=List.Write&redirect_uri={$this->redirect_uri}";

		return $url;

	}

	/**
	 * Get refresh token based on auth_code, obtained by user consent
	 *
	 * @param string $auth_code
	 *
	 * @return string
	 * @throws \Exception
	 */
	public function getRefreshTokenFromAuthCode( $auth_code ) {

		return $this->getToken( 'authorization_code', $auth_code, 'refresh_token' );

	}

	/**
	 * Get new short-lived access token based on refresh token
	 *
	 * @param string $refresh_token
	 *
	 * @return string
	 */
	public function getAccessToken( $refresh_token ) {

		return $this->getToken( 'refresh_token', $refresh_token, 'access_token' );

	}

	/**
	 * Make OAuth request for access and refresh tokens
	 *
	 * Supports request based on auth_code or refresh_token
	 *
	 * @param string $grant_type [ 'authorization_code', 'refresh_token' ]
	 * @param string $auth_principal
	 * @param string $result_property Proprty of response, which to return
	 *
	 * @return string
	 * @throws \Exception
	 */
	protected function getToken( $grant_type, $auth_principal, $result_property ) {

		$realm_id = $this->getRealm();

		$guzzle = $this->createGuzzleClient();

		$root_site_host = parse_url( $this->site_url, PHP_URL_HOST );

		if( $grant_type === 'authorization_code' ) {
			$principal = [ 'code' => $auth_principal ];
		} elseif( $grant_type === 'refresh_token' ) {
			$principal = [ 'refresh_token' => $auth_principal ];
		} else {
			throw new \Exception( "Unknown grant type" );
		}

		$response = $guzzle->request( 'POST', "https://accounts.accesscontrol.windows.net/{$realm_id}/tokens/OAuth/2" , [
			'form_params' => array_merge( [
				'grant_type' => $grant_type,
				'client_id' => $this->app_id . '@' . $realm_id,
				'client_secret' => $this->app_secret,
				'redirect_uri' => $this->redirect_uri,
				'resource' => "00000003-0000-0ff1-ce00-000000000000/{$root_site_host}@{$realm_id}" //Sharepoint constant
			], $principal )
		] );

		$response_data = json_decode( $response->getBody() );

		if( !property_exists( $response_data, $result_property ) ) throw new \Exception( 'Refresh token not present in Oauth response.' );

		return $response_data->$result_property;

	}

	/**
	 * Get Realm ID from unauthorized request headers to client.svc
	 *
	 * @return string
	 * @throws \Exception
	 */
	protected function getRealm() {

		$guzzle = $this->createGuzzleClient();

		$request = $guzzle->request( 'GET', $this->site_url . '/_vti_bin/client.svc', [
			'http_errors' => false,
			'headers' => [
				'Authorization' => 'Bearer'
			]
		] );

		$autheticate_header = $request->getHeader( 'WWW-Authenticate' );

		if( empty( $autheticate_header ) ) throw new \Exception( 'Invalid response for Realm lookup.' );

		preg_match( '/realm\=\"(.+?)\"/i', $autheticate_header[0], $matches );
		if( !isset( $matches[1] ) ) throw new \Exception( 'Invalid response for Realm lookup. No realm id in header.' );

		return $matches[ 1 ];

	}

	/**
	 * Create guzzle client with preconfigured parameters
	 *
	 * If we have app.debug in standard laravel config set to true, don't check for valid SSL certificate (for dev enviroments)
	 *
	 * @return \GuzzleHttp\Client
	 */
	protected function createGuzzleClient() {

		$config_repo = $this->app->make( Repository::class );
		if( $config_repo->has( 'app.debug' ) && $config_repo->get( 'app.debug' ) == true ) { // Loose compare to acomodate different .env habits
			$verify = false;
		} else {
			$verify = true;
		}

		return new \GuzzleHttp\Client( [ 'verify' => $verify ] );

	}

	/**
	 * Upload file to relative path on server given refresh token
	 *
	 * @param string $refresh_token
	 * @param string $file_path
	 * @param string $file_name
	 * @param StreamInterface $file_stream
	 *
	 * @return bool True on success upload
	 */
	public function uploadFile( $refresh_token, $file_path, $file_name, $file_stream ) {
        $this->createFolder($refresh_token, $file_path, function () use ($refresh_token, $file_path, $file_name, $file_stream) {
            $start_time = ( new \DateTime() )->sub( new \DateInterval( 'P1M' ) ); // Subtract one minute due to different server time sync
            $parsed_url = parse_url("{$this->site_url}/{$this->doc_library}/{$file_path}", PHP_URL_PATH);
            $guzzle = $this->createGuzzleClient();
            $response = $guzzle->request(
                'POST',
                "{$this->site_url}/_api/web/GetFolderByServerRelativeUrl('{$parsed_url}')/Files/add(url='{$file_name}',overwrite=true)",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->getAccessToken( $refresh_token ),
                        'accept' => 'application/json; odata=verbose'
                    ],
                    'body' => $file_stream
                ]
            );

            $response_data = json_decode( $response->getBody() );
            $last_modified_date = new \DateTime( $response_data->d->TimeLastModified );

            if( $last_modified_date > $start_time ) {
                return true;
            }
            return false;
        });
	}

    public function createFolder($refresh_token, $folderPath, $closure)
    {
        $folders = explode('/', $folderPath);
        $create_folder = $this->_createFolder($refresh_token, '', $folders);
        if ($create_folder == 'success') {
            return $closure($create_folder);
        }
        if ($create_folder == 'error') {
            return false;
        }
    }

	private function _createFolder( $refresh_token, $initialPath, $folderArray ) {
        if (empty($folderArray)) {
            return 'success';
        }
		$guzzle = $this->createGuzzleClient();
        $finalPath = '';
        if (empty($initialPath)) {
            $finalPath = $folderArray[0];
        } else {
            $finalPath = ltrim("{$initialPath}/{$folderArray[0]}", "/");
        }
        try {
            $guzzle->request(
                'POST',
                "{$this->site_url}/_api/web/folders",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->getAccessToken( $refresh_token ),
                        'Content-Type' => 'application/json;odata=verbose',
                        'Accept' => 'application/json;odata=verbose',
                    ],
                    'json' => [
                        '__metadata' => [
                            'type' => 'SP.Folder',
                        ],
                        'ServerRelativeUrl' => "{$this->site_url}/{$this->doc_library}/{$finalPath}",
                    ],
                ],
            );
            $initialPath = "{$initialPath}/{$folderArray[0]}";
            array_shift($folderArray);
            return $this->_createFolder($refresh_token, $initialPath, $folderArray);
        } catch (\Exception $e) {
            return 'error';
        }
	}

    public function deleteFile($refresh_token, $file_path, $file_name)
    {
		$guzzle = $this->createGuzzleClient();
        try {
            $parsed_url = parse_url("{$this->site_url}/{$this->doc_library}/{$file_path}/{$file_name}", PHP_URL_PATH);
            $guzzle->request(
                'POST',
                "{$this->site_url}/_api/web/GetFileByServerRelativeUrl('{$parsed_url}')",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->getAccessToken( $refresh_token ),
                        'Content-Type' => 'application/json;odata=verbose',
                        'Accept' => 'application/json;odata=verbose',
                        'X-HTTP-Method' => 'DELETE',
                        'IF-MATCH' => '*',
                    ],
                ],
            );
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

	/**
	 * Upload file to relative path on server given refresh token
	 *
	 * @param string $refresh_token
	 * @param string $file_path
	 * @param string $file_name
	 * @param StreamInterface $file_stream
	 *
	 * @return bool True on success upload
	 */
	public function getFormDigest($refresh_token) {

		$start_time = ( new \DateTime() )->sub( new \DateInterval( 'P1M' ) ); // Subtract one minute due to different server time sync

		$guzzle = $this->createGuzzleClient();
		$response = $guzzle->request(
			'POST',
			"{$this->site_url}/_api/contextinfo",
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $this->getAccessToken( $refresh_token ),
					'accept' => 'application/json; odata=verbose',
                    'content-type' => 'application/json;odata=verbose',
                    // 'X-RequestDigest' => '123',
				],
			]
		);

		$response_data = json_decode( $response->getBody() );
        return $response_data;
		$last_modified_date = new \DateTime( $response_data->d->TimeLastModified );

		if( $last_modified_date > $start_time ) {
			return true;
		}
		return false;

	}

}
