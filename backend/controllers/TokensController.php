<?php

namespace suplascripts\controllers;

use Assert\Assert;
use Slim\Http\Response;
use suplascripts\controllers\exceptions\ApiException;
use suplascripts\models\Client;
use suplascripts\models\JwtToken;
use suplascripts\models\User;

class TokensController extends BaseController {

    public function createTokenAction() {
        $body = $this->request()->getParsedBody();
        return $this->authenticateUser($body);
    }

    private function authenticateUser(array $body): Response {
        Assert::that($body)->notEmptyKey(User::USERNAME)->notEmptyKey(User::PASSWORD);
        $usernameOrEmail = $body[User::USERNAME];
        $password = $body[User::PASSWORD];
        $user = $this->findMatchingUser($usernameOrEmail, $password);
        $token = JwtToken::create()->user($user)->rememberMe($body['rememberMe'] ?? false)->issue();
        $this->getApp()->getContainer()['currentUser'] = $user;
        $user->trackLastLogin();
        return $this->response(['token' => $token]);
    }

    public function createTokenForClientAction() {
        $body = $this->request()->getParsedBody();
        $this->authenticateUser($body);
        return $this->getApp()->db->getConnection()->transaction(function () use ($body) {
            $client = new Client([Client::LABEL => $body['label'] ?? 'Client']);
            $client->save();
            $token = JwtToken::create()->client($client)->issue();
            return $this->response(['token' => $token])->withStatus(201);
        });
    }

    public function oauthAuthenticateAction() {
        $code = $this->request()->getParam('code');
        if ($code) {
            $suplaDomain = base64_decode(explode('.', $code)[1] ?? '');
            $handle = curl_init($suplaDomain . '/oauth/v2/token');
            $data = [
                'grant_type' => 'authorization_code',
                'client_id' => $this->getApp()->getSetting('oauth')['clientId'],
                'client_secret' => $this->getApp()->getSetting('oauth')['secret'],
                'redirect_uri' => 'http://suplascripts.local/api/oauth',
                'code' => $code
            ];
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
            $resp = curl_exec($handle);
            if ($resp) {
                $resp = json_decode($resp, true);
                if (isset($resp['access_token'])) {
                    return 'OK: ' . $resp['access_token'];
                }
                else {
                    return json_encode($resp);
                }
            }
        }
        return 'Smuteczek';
    }

    public function refreshTokenAction() {
        $currentToken = $this->getApp()->currentToken;
        $user = $this->getCurrentUser();
        $newToken = JwtToken::create()
            ->basedOnPreviousToken($currentToken)
            ->user($user)
            ->issue();
        if ($user) {
            $user->trackLastLogin();
        }
        return $this->response(['token' => $newToken]);
    }

    private function findMatchingUser($username, $plainPassword) {
        $user = User::findByUsername($username);
        if ($user != null && $user->isPasswordValid($plainPassword)) {
            return $user;
        }
        throw new ApiException('Invalid username or password', 401);
    }
}
