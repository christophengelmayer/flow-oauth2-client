<?php

namespace Flownative\OAuth2\Client;

use Doctrine\Common\Persistence\ObjectManager as DoctrineObjectManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager as DoctrineEntityManager;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\TransactionRequiredException;
use Flownative\OpenIdConnect\Client\OAuthProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use League\OAuth2\Client\Tool\RequestFactory;
use Neos\Cache\Exception;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Flow\Http\Request;
use Neos\Flow\Http\Uri;
use Neos\Flow\Log\SystemLoggerInterface;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Session\SessionInterface;
use Psr\Http\Message\RequestInterface;

abstract class OAuthClient
{
    public const STATE_QUERY_PARAMETER_NAME = 'flownative_oauth2_state';

    /**
     * @Flow\Inject
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * @Flow\Inject
     * @var Bootstrap
     */
    protected $bootstrap;

    /**
     * @Flow\InjectConfiguration(path="http.baseUri", package="Neos.Flow")
     * @var string
     */
    protected $flowBaseUriSetting;

    /**
     * @var Client
     */
    protected $httpClient;

    /**
     * @Flow\Inject
     * @var SessionInterface
     */
    protected $session;

    /**
     * @var DoctrineEntityManager
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var SystemLoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var VariableFrontend
     */
    protected $stateCache;

    /**
     * @var string
     */
    protected $serviceName;

    /**
     * @param string $serviceName
     */
    public function __construct(string $serviceName)
    {
        $this->serviceName = $serviceName;
    }

    /**
     * @param DoctrineObjectManager $entityManager
     * @return void
     */
    public function injectEntityManager(DoctrineObjectManager $entityManager): void
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Returns the service type, i.e. a specific implementation of this client to use
     *
     * @return string For example, "FlownativeBeach", "oidc", ...
     */
    abstract public function getServiceType(): string;

    /**
     * Returns the service name, i.e. something like an instance name of the concrete implementation of this client
     *
     * @return string For example, "Github", "MySpecialService", ...
     */
    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    /**
     * Returns the OAuth server's base URI
     *
     * @return string For example https://myservice.flownative.com
     */
    abstract public function getBaseUri(): string;

    /**
     * Returns the current client id (for sending authenticated requests)
     *
     * @return string The client id which is known by the OAuth2 server
     */
    abstract public function getClientId(): string;

    /**
     * Returns the OAuth service endpoint for the access token.
     * Override this method if needed.
     *
     * @return string
     */
    public function getAccessTokenUri(): string
    {
        return $this->getBaseUri() . '/oauth/token';
    }

    /**
     * Returns the OAuth service endpoint for authorizing a token.
     * Override this method if needed.
     *
     * @return string
     */
    public function getAuthorizeTokenUri(): string
    {
        return $this->getBaseUri() . '/oauth/token/authorize';
    }

    /**
     * Returns the OAuth service endpoint for accessing the resource owner details.
     * Override this method if needed.
     *
     * @return string
     */
    public function getResourceOwnerUri(): string
    {
        return $this->getBaseUri() . '/oauth/token/resource';
    }

    /**
     * Returns a factory for requests used by this OAuth client.
     *
     * You may override this method an provide a custom request factory, for example for adding
     * additional headers (e.g. User-Agent) to every request.
     *
     * @return RequestFactory
     */
    public function getRequestFactory(): RequestFactory
    {
        return new RequestFactory();
    }

    /**
     * Add credentials for a Client Credentials Grant
     *
     * @param string $clientId
     * @param string $clientSecret
     * @param string $scope
     * @return void
     * @throws IdentityProviderException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function addClientCredentials(string $clientId, string $clientSecret, string $scope = ''): void
    {
        $oAuthProvider = $this->createOAuthProvider($clientId, $clientSecret);

        try {
            $this->logger->log(sprintf($this->getServiceType() . 'Setting client credentials for client "%s" using a %s bytes long secret.', $clientId, strlen($clientSecret)), LOG_INFO);

            $authorizationId = sprintf('%s-%s', $this->getServiceName(), $clientId);
            $oldOAuthorization = $this->getAuthorization($authorizationId);
            if ($oldOAuthorization !== null) {
                $this->entityManager->remove($oldOAuthorization);
                $this->entityManager->flush();

                $this->logger->log(sprintf($this->getServiceType() . 'Removed old OAuth2 authorization for client "%s".', $clientId), LOG_INFO);
            }

            $accessToken = $oAuthProvider->getAccessToken('client_credentials');
            $authorization = $this->createNewAuthorization($authorizationId, $clientId, $clientSecret, 'client_credentials', $accessToken, $scope);

            $this->logger->log(sprintf($this->getServiceType() . 'Persisted new OAuth2 authorization %s for client "%s" with expiry time %s.', $authorizationId, $clientId, $accessToken->getExpires()), LOG_INFO);

            $this->entityManager->persist($authorization);
            $this->entityManager->flush();
        } catch (IdentityProviderException $exception) {
            throw $exception;
        }
    }

    /**
     * Start OAuth authorization
     *
     * @param string $clientId The client id, as provided by the OAuth server
     * @param string $clientSecret The client secret, provided by the OAuth server
     * @param Uri $returnToUri URI to return to when authorization is finished
     * @param array $scopes Scopes to request for authorization
     * @return Uri The URL the browser should redirect to, asking the user to authorize
     * @throws OAuthClientException
     */
    public function startAuthorization(string $clientId, string $clientSecret, Uri $returnToUri, array $scopes = []): Uri
    {
        $oAuthProvider = $this->createOAuthProvider($clientId, $clientSecret);
        $authorizationUri = new Uri($oAuthProvider->getAuthorizationUrl(['scope' => implode(' ', $scopes)]));
        $stateIdentifier = $oAuthProvider->getState();

        try {
            $this->stateCache->set(
                $stateIdentifier,
                [
                    'clientId' => $clientId,
                    'clientSecret' => $clientSecret,
                    'returnToUri' => (string)$returnToUri
                ]
            );
        } catch (Exception $exception) {
            throw new OAuthClientException(sprintf('Failed setting cache entry for OAuth2 authorization: %s', $exception->getMessage()), 1560178858);
        }

        $this->logger->log(sprintf('Flownative OAuth2 Client (%s): Starting authorization %s using client id "%s" and a %s bytes long secret, returning to "%s".', $this->getServiceType(), $stateIdentifier, $clientId, strlen($clientSecret), $returnToUri), LOG_INFO);
        return $authorizationUri;
    }

    /**
     * Finish an OAuth authorization
     *
     * @param string $code The authorization code given by the OAuth server
     * @param string $stateIdentifier The authorization identifier, passed back by the OAuth server as the "state" parameter
     * @param string $scope The scope for the granted authorization (syntax varies depending on the service)
     * @return Uri The URI to return to
     * @throws OAuthClientException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function finishAuthorization(string $code, string $stateIdentifier, string $scope): Uri
    {
        $stateFromCache = $this->stateCache->get($stateIdentifier);
        if (empty($stateFromCache)) {
            throw new OAuthClientException(sprintf('Flownative OAuth2 Client: Finishing authorization failed because oAuth state %s could not be retrieved from the state cache.', $stateIdentifier), 1558956494);
        }

        $clientId = $stateFromCache['clientId'];
        $clientSecret = $stateFromCache['clientSecret'];
        $oAuthProvider = $this->createOAuthProvider($clientId, $clientSecret);

        try {
            $this->logger->log(sprintf('Flownative OAuth2 Client (%s): Finishing authorization for client "%s", state "%s", using a %s bytes long secret.', $this->getServiceType(), $clientId, $stateIdentifier, strlen($clientSecret)), LOG_INFO);

            $oldOAuthToken = $this->entityManager->find(Authorization::class, ['authorizationId' => $stateIdentifier]);
            if ($oldOAuthToken !== null) {
                $this->entityManager->remove($oldOAuthToken);
                $this->entityManager->flush();

                $this->logger->log(sprintf($this->getServiceType() . ': Removed old OAuth token "%s".', $stateIdentifier), LOG_INFO);
            }
            $accessToken = $oAuthProvider->getAccessToken('authorization_code', ['code' => $code]);
            $authorization = $this->createNewAuthorization($stateIdentifier, $clientId, $clientSecret, 'authorization_code', $accessToken, $scope);

            $this->logger->log(sprintf('Flownative OAuth2 Client: Persisted new OAuth token for authorization "%s" with expiry time %s.', $stateIdentifier, $accessToken->getExpires()), LOG_INFO);

            $this->entityManager->persist($authorization);
            $this->entityManager->flush();
        } catch (IdentityProviderException $exception) {
            throw new OAuthClientException($exception->getMessage(), 1511187001671, $exception);
        }

        $returnToUri = new Uri($stateFromCache['returnToUri']);
        return $returnToUri->withQuery(trim($returnToUri->getQuery() . '&' . self::STATE_QUERY_PARAMETER_NAME . '=' . $stateIdentifier, '&'));
    }

    /**
     * Refresh an OAuth authorization
     *
     * @param string $authorizationId
     * @param string $clientId
     * @param string $returnToUri
     * @return string
     * @throws OAuthClientException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function refreshAuthorization(string $authorizationId, string $clientId, string $returnToUri): string
    {
        $authorization = $this->entityManager->find(Authorization::class, ['authorizationId' => $authorizationId]);
        if (!$authorization instanceof Authorization) {
            throw new OAuthClientException(sprintf('Flownative OAuth2 Client: Could not refresh OAuth2 token because authorization %s was not found in our database.', $authorization), 1505317044316);
        }
        $oAuthProvider = $this->createOAuthProvider($authorizationId, $clientId, $authorization->clientSecret);

        $this->logger->log(sprintf($this->getServiceType() . ': Refreshing authorization %s for client "%s" using a %s bytes long secret and refresh token "%s".', $authorizationId, $clientId, strlen($authorization->clientSecret), $authorization->refreshToken), LOG_INFO);

        try {
            $accessToken = $oAuthProvider->getAccessToken('refresh_token', ['refresh_token' => $authorization->refreshToken]);
            $authorization->accessToken = $accessToken->getToken();
            $authorization->expires = ($accessToken->getExpires() ? \DateTimeImmutable::createFromFormat('U', $accessToken->getExpires()) : null);

            $this->logger->log(sprintf($this->getServiceType() . ': New access token is "%s", refresh token is "%s".', $authorization->accessToken, $authorization->refreshToken), LOG_DEBUG);

            $this->entityManager->persist($authorization);
            $this->entityManager->flush();
        } catch (IdentityProviderException $exception) {
            throw new OAuthClientException($exception->getMessage(), 1511187196454, $exception);
        }

        return $returnToUri;
    }

    /**
     * @param string $authorizationId
     * @return Authorization|null
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function getAuthorization(string $authorizationId): ?Authorization
    {
        $oAuthToken = $this->entityManager->find(Authorization::class, ['authorizationId' => $authorizationId], LockMode::NONE);
        return ($oAuthToken instanceof Authorization) ? $oAuthToken : null;
    }

    /**
     * Returns a prepared request which provides the needed header for OAuth authentication
     *
     * @param string $relativeUri A relative URI of the web server, prepended by the base URI
     * @param string $method The HTTP method, for example "GET" or "POST"
     * @param array $bodyFields Associative array of body fields to send (optional)
     * @return RequestInterface
     * @throws IdentityProviderException
     * @throws OAuthClientException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function getAuthenticatedRequest(string $relativeUri, string $method = 'GET', array $bodyFields = []): RequestInterface
    {
        $oAuthToken = $this->getAuthorization();
        if (!$oAuthToken instanceof Authorization) {
            throw new OAuthClientException('No OAuthToken found.', 1505321014388);
        }

        $oAuthProvider = $this->createOAuthProvider($oAuthToken->clientId, $oAuthToken->clientSecret);

        if ($oAuthToken->expires < new \DateTimeImmutable()) {
            switch ($oAuthToken->grantType) {
                case 'authorization_code':
                    $this->refreshAuthorization($oAuthToken->clientId, '');
                    $oAuthToken = $this->getAuthorization();
                break;
                case 'client_credentials':
                    try {
                        $newAccessToken = $oAuthProvider->getAccessToken('client_credentials');
                    } catch (IdentityProviderException $exception) {
                        $this->logger->log(sprintf($this->getServiceType() . 'Failed retrieving new OAuth access token for client "%s" (client credentials grant): %s', $oAuthToken->clientId, $exception->getMessage()), LOG_ERR);
                        throw $exception;
                    }

                    $oAuthToken->accessToken = $newAccessToken->getToken();
                    $oAuthToken->expires = ($newAccessToken->getExpires() ? \DateTimeImmutable::createFromFormat('U', $newAccessToken->getExpires()) : null);

                    $this->logger->log(sprintf($this->getServiceType() . 'Persisted new OAuth token for client "%s" with expiry time %s.', $oAuthToken->clientId, $newAccessToken->getExpires()), LOG_INFO);

                    $this->entityManager->persist($oAuthToken);
                    $this->entityManager->flush();
                break;
            }
        }

        $body = ($bodyFields !== [] ? \GuzzleHttp\json_encode($bodyFields) : '');

        return $oAuthProvider->getAuthenticatedRequest(
            $method,
            $this->getBaseUri() . $relativeUri,
            $oAuthToken->accessToken,
            [
                'headers' => [
                    'Content-Type' => 'application/json'
                ],
                'body' => $body
            ]
        );
    }

    /**
     * @param string $relativeUri
     * @param string $method
     * @param array $bodyFields
     * @return Response
     * @throws IdentityProviderException
     * @throws OAuthClientException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     * @throws GuzzleException
     */
    public function sendAuthenticatedRequest(string $relativeUri, string $method = 'GET', array $bodyFields = []): Response
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client();
        }
        return $this->httpClient->send($this->getAuthenticatedRequest($relativeUri, $method, $bodyFields));
    }

    /**
     * @return string
     * @throws
     */
    public function renderFinishAuthorizationUri(): string
    {
        $currentRequestHandler = $this->bootstrap->getActiveRequestHandler();
        if ($currentRequestHandler instanceof HttpRequestHandlerInterface) {
            $httpRequest = $currentRequestHandler->getHttpRequest();
        } else {
            putenv('FLOW_REWRITEURLS=1');
            $httpRequest = Request::createFromEnvironment();
            $httpRequest->setBaseUri(new Uri($this->flowBaseUriSetting));
        }
        $actionRequest = new ActionRequest($httpRequest);

        $this->uriBuilder->reset();
        $this->uriBuilder->setRequest($actionRequest);
        $this->uriBuilder->setCreateAbsoluteUri(true);

        try {
            $uri = $this->uriBuilder->
            reset()->
            setCreateAbsoluteUri(true)->
            uriFor('finishAuthorization', ['serviceType' => $this->getServiceType(), 'serviceName' => $this->getServiceName()], 'OAuth', 'Flownative.OAuth2.Client');
            return $uri;
        } catch (MissingActionNameException $e) {
            return '';
        }
    }

    /**
     * Create a new OAuthToken instance
     *
     * @param string $authorizationId
     * @param string $clientId
     * @param string $clientSecret
     * @param string $grantType
     * @param AccessTokenInterface $accessToken
     * @param string $scope
     * @return Authorization
     */
    protected function createNewAuthorization(string $authorizationId, string $clientId, string $clientSecret, string $grantType, AccessTokenInterface $accessToken, string $scope): Authorization
    {
        $authorization = new Authorization();
        $authorization->authorizationId = $authorizationId;
        $authorization->clientId = $clientId;
        $authorization->serviceName = $this->getServiceType();
        $authorization->grantType = $grantType;
        $authorization->clientSecret = $clientSecret;
        $authorization->accessToken = $accessToken->getToken();
        $authorization->refreshToken = $accessToken->getRefreshToken();
        $authorization->expires = ($accessToken->getExpires() ? \DateTimeImmutable::createFromFormat('U', $accessToken->getExpires()) : null);
        $authorization->scope = $scope;
        $authorization->tokenValues = $accessToken->getValues();

        return $authorization;
    }

    /**
     * @param string $clientId
     * @param string $clientSecret
     * @return GenericProvider
     */
    protected function createOAuthProvider(string $clientId, string $clientSecret): GenericProvider
    {
        return new GenericProvider([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri' => $this->renderFinishAuthorizationUri(),
            'urlAuthorize' => $this->getAuthorizeTokenUri(),
            'urlAccessToken' => $this->getAccessTokenUri(),
            'urlResourceOwnerDetails' => $this->getResourceOwnerUri(),
        ], [
            'requestFactory' => $this->getRequestFactory()
        ]);
    }
}
