<?php

namespace AppBundle\Security\Firewall;

use AppBundle\Security\Authentication\Token\BearerToken;
use AppBundle\Security\StoreTokenAuthenticator;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Guard\JWTTokenAuthenticator;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\Security\Core\Authentication\AuthenticationManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Firewall\ListenerInterface;
use Trikoder\Bundle\OAuth2Bundle\Security\Authentication\Token\OAuth2Token;

class TokenBearerListener implements ListenerInterface
{
    protected $tokenStorage;
    protected $authenticationManager;
    protected $jwtTokenAuthenticator;
    protected $jwtTokenAuthenticatorNotExpiring;
    protected $httpMessageFactory;

    public function __construct(
        TokenStorageInterface $tokenStorage,
        AuthenticationManagerInterface $authenticationManager,
        JWTTokenAuthenticator $jwtTokenAuthenticator,
        StoreTokenAuthenticator $jwtTokenAuthenticatorNotExpiring,
        HttpMessageFactoryInterface $httpMessageFactory
    )
    {
        $this->tokenStorage = $tokenStorage;
        $this->authenticationManager = $authenticationManager;
        $this->jwtTokenAuthenticator = $jwtTokenAuthenticator;
        $this->jwtTokenAuthenticatorNotExpiring = $jwtTokenAuthenticatorNotExpiring;
        $this->httpMessageFactory = $httpMessageFactory;
    }

    public function handle(GetResponseEvent $event)
    {
        $request = $event->getRequest();

        $supports = $this->jwtTokenAuthenticator->supports($request);

        // There is no Authentication header
        if (!$supports) {
            return;
        }

        try {
            $lexikToken = $this->jwtTokenAuthenticator->getCredentials($request);
        } catch (InvalidTokenException $e) {
            // This may happen when handling legacy "store tokens"
            // which don't have an "exp" claim.
            // FIXME Remove this when "store tokens" are deprecated
            try {

                $lexikToken = $this->jwtTokenAuthenticatorNotExpiring->getCredentials($request);

                $payload = $lexikToken->getPayload();

                if (!isset($payload['store'])) {
                    throw $e;
                }

            } catch (AuthenticationException $e) {
                throw $e;
            }
        } catch (AuthenticationException $e) {

            // The token is not valid (invalid signature, expired...)
            $response = $this->jwtTokenAuthenticator->onAuthenticationFailure($request, $e);

            $event->setResponse($response);
            return;
        }

        $trikoderToken = new OAuth2Token($this->httpMessageFactory->createRequest($event->getRequest()), null);

        // We create a "composed" token
        $token = new BearerToken($lexikToken, $trikoderToken);

        try {

            $authToken = $this->authenticationManager->authenticate($token);
            $this->tokenStorage->setToken($authToken);

            return;

        } catch (AuthenticationException $e) {

            $response = $this->jwtTokenAuthenticator->onAuthenticationFailure($request, $e);

            $event->setResponse($response);
            return;
        }

        $response = new Response();
        $response->setStatusCode(Response::HTTP_UNAUTHORIZED);
        $event->setResponse($response);
    }
}
