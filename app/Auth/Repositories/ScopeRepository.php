<?php

namespace Northstar\Auth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Northstar\Auth\Entities\ScopeEntity;
use Northstar\Auth\Scope;

class ScopeRepository implements ScopeRepositoryInterface
{
    /**
     * Return information about a scope.
     *
     * @param string $identifier The scope identifier
     * @return \League\OAuth2\Server\Entities\ScopeEntityInterface
     */
    public function getScopeEntityByIdentifier($identifier)
    {
        $scopes = Scope::all();

        if (array_key_exists($identifier, $scopes) === false) {
            return null;
        }

        $entity = new ScopeEntity();
        $entity->setIdentifier($identifier);

        return $entity;
    }

    /**
     * Given a client, grant type, and optional user identifier validate the set of requested scopes
     * are valid and optionally append additional scopes or remove requested scopes.
     *
     * @param ScopeEntityInterface[] $scopes
     * @param string $grantType
     * @param \League\OAuth2\Server\Entities\ClientEntityInterface $clientEntity
     * @param null|string $userIdentifier
     * @return \League\OAuth2\Server\Entities\ScopeEntityInterface[]
     */
    public function finalizeScopes(array $scopes, $grantType, ClientEntityInterface $clientEntity, $userIdentifier = null)
    {
        $allowedScopes = $clientEntity->getAllowedScopes();
        $filteredScopes = array_filter($scopes, function (ScopeEntity $scope) use ($allowedScopes) {
            return in_array($scope->getIdentifier(), $allowedScopes);
        });

        return $filteredScopes;
    }
}
