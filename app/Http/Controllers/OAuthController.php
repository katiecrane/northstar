<?php

namespace Northstar\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Contracts\Auth\Guard as Auth;
use League\OAuth2\Server\Exception\OAuthServerException;
use Northstar\Auth\Encrypter;
use Northstar\Models\RefreshToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use League\OAuth2\Server\AuthorizationServer;

class OAuthController extends Controller
{
    /**
     * The OAuth authorization server.
     * @var AuthorizationServer
     */
    protected $oauth;

    /**
     * The authentication guard.
     * @var \Northstar\Auth\NorthstarTokenGuard
     */
    protected $auth;

    /**
     * The encrypter/decrypter.
     * @var Encrypter
     */
    protected $encrypter;

    /**
     * Make a new OAuthController, inject dependencies,
     * and set middleware for this controller's methods.
     *
     * @param AuthorizationServer $oauth
     * @param Auth $auth
     * @param Encrypter $encrypter
     */
    public function __construct(AuthorizationServer $oauth, Auth $auth, Encrypter $encrypter)
    {
        $this->oauth = $oauth;
        $this->auth = $auth;
        $this->encrypter = $encrypter;

        $this->middleware('auth', ['only' => 'invalidateToken']);
    }

    /**
     * Show the login form for authenticating a user using one of the
     * authentication code grant.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return \Illuminate\Http\Response
     */
    public function authorize(ServerRequestInterface $request, ResponseInterface $response)
    {
        return $this->respond('Not yet implemented.', 501);
    }

    /**
     * Authenticate a registered user using one of the supported OAuth
     * grants and return token details.
     *
     * @see RFC6749 OAuth 2.0 <https://tools.ietf.org/html/rfc6749>
     *      RFC7519 JSON Web Token <https://tools.ietf.org/html/rfc7519>
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return \Illuminate\Http\Response
     */
    public function createToken(ServerRequestInterface $request, ResponseInterface $response)
    {
        return $this->oauth->respondToAccessTokenRequest($request, $response);
    }

    /**
     * Invalidate the provided refresh token, preventing it from being used
     * to generate new access tokens in the future. This is roughly equivalent
     * to "logging out" the user.
     *
     * @see RFC7009 OAuth2 Token Revocation <https://tools.ietf.org/html/rfc7009>
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     * @throws OAuthServerException
     */
    public function invalidateToken(Request $request)
    {
        $this->validate($request, [
            'token' => 'required',
            'token_type_hint' => 'in:refresh_token', // Since we cannot revoke access tokens, refuse to try.
        ]);

        try {
            $refreshToken = $this->encrypter->decryptData($request->input('token'));
        } catch (\LogicException $e) {
            // Per RFC7009, invalid tokens do _not_ trigger an error response.
            return $this->respond('That refresh token has been successfully revoked.', 200);
        }

        // Make sure that the authenticated user is allowed to do this.
        if ($this->auth->user()->getAuthIdentifier() !== $refreshToken['user_id']) {
            throw OAuthServerException::accessDenied('That refresh token does not belong to the currently authorized user.');
        }

        $token = RefreshToken::where('token', $refreshToken['refresh_token_id'])->first();

        // Delete the refresh token, if it exists.
        if ($token) {
            $token->delete();
        }

        return $this->respond('That refresh token has been successfully revoked.', 200);
    }
}
