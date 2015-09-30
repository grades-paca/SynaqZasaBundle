<?php
namespace Synaq\ZasaBundle\Connector;

use Synaq\CurlBundle\Curl\Wrapper;
use Synaq\ZasaBundle\Exception\SoapFaultException;
use Synaq\ZasaBundle\Util\Array2Xml;
use Synaq\ZasaBundle\Util\Xml2Array;

class ZimbraConnector
{
    /**
     * @var Wrapper
     */
    private $httpClient;

    /**
     * @var string
     */
    private $server;

    /**
     * @var string
     */
    private $adminUser;

    /**
     * @var string
     */
    private $adminPass;

    /**
     * @var string
     */
    private $authToken = false;

    /**
     * @var string
     */
    private $delegatedAuthToken = false;

    /**
     * @var string
     */
    private $delegatedAuthAccount = false;

    /**
     * @var bool
     */
    private $fopen = true;


    /**
     * @param \Synaq\CurlBundle\Curl\Wrapper $httpClient
     * @param $server
     * @param $adminUser
     * @param $adminPass
     * @param bool $fopen
     */
    public function __construct(Wrapper $httpClient, $server, $adminUser, $adminPass, $fopen = true)
    {
        $this->httpClient = $httpClient;
        $this->server = $server;
        $this->adminUser = $adminUser;
        $this->adminPass = $adminPass;
        $this->fopen = $fopen;

        $this->login();
    }

    private function request($requestType, $attributes = array(), $parameters = array(), $delegate = false, $delegateType = 'Mail')
    {
        $request = $this->buildRequest($requestType, $attributes, $parameters, $delegate, $delegateType);
        $response = $this->submitRequest($request);

        return $response['soap:Envelope']['soap:Body'][$requestType . 'Response'];
    }

    /**
     * @param $requestType
     * @param $attributes
     * @param $parameters
     * @param $delegate
     * @param $delegateType
     * @return string
     */
    private function buildRequest($requestType, $attributes, $parameters, $delegate, $delegateType)
    {
        $requestAsArray = $this->buildRequestAsArray($requestType, $attributes, $parameters, $delegate, $delegateType);
        $requestAsXml = Array2Xml::createXML('soap:Envelope', $requestAsArray)->saveXML();
        return $requestAsXml;
    }

    /**
     * @param $request
     * @return \DOMDocument
     * @throws SoapFaultException
     * @throws \Exception
     */
    private function submitRequest($request)
    {
        $response = $this->httpClient->post($this->server, $request, array("Content-type: application/xml"), array(),
            $this->fopen);
        $responseContent = $response->getBody();
        $responseArray = Xml2Array::createArray($responseContent);
        $this->identifySoapFault($responseArray);

        return $responseArray;
    }

    /**
     * @param $responseArray
     * @throws SoapFaultException
     */
    private function identifySoapFault($responseArray)
    {
        if (array_key_exists('soap:Fault', $responseArray['soap:Envelope']['soap:Body'])) {

            throw new SoapFaultException('Zimbra Soap Fault: ' . $responseArray['soap:Envelope']['soap:Body']['soap:Fault']['soap:Reason']['soap:Text']);
        }
    }

    /**
     * @param $request
     * @param $attributes
     * @param $parameters
     * @param $delegate
     * @param $delegateType
     * @return array
     */
    private function buildRequestAsArray($request, $attributes, $parameters, $delegate, $delegateType)
    {
        $header = $this->buildRequestHeaders($delegate);

        if ($delegate) {
            $attributes['xmlns'] .= 'urn:zimbra' . $delegateType;
        } else {
            $attributes['xmlns'] = 'urn:zimbraAdmin';
        }

        $body[$request . 'Request'] = array_merge(array('@attributes' => $attributes), $parameters);

        $message = array(
            '@attributes' => array(
                'xmlns:soap' => 'http://www.w3.org/2003/05/soap-envelope'
            ),
            'soap:Header' => $header,
            'soap:Body' => $body
        );
        return $message;
    }

    /**
     * @param $delegate
     * @return array
     */
    private function buildRequestHeaders($delegate)
    {
        if ($delegate) {
            $header = $this->buildDelegateAuthRequestHeaders();
            return $header;
        } elseif ($this->authToken) {
            $header = $this->buildAuthRequestHeaders();
            return $header;
        } else {
            $header = $this->buildNoAuthRequestHeaders();
            return $header;
        }
    }

    /**
     * @return array
     */
    private function buildDelegateAuthRequestHeaders()
    {
        $header = array(
            'context' => array(
                '@attributes' => array(
                    'xmlns' => 'urn:zimbra'
                ),
                'authToken' => array(
                    '@value' => $this->delegatedAuthToken
                ),
                'account' => array(
                    '@attributes' => array(
                        'by' => 'name'
                    ),
                    '@value' => $this->delegatedAuthAccount
                )
            )
        );
        return $header;
    }

    /**
     * @return array
     */
    private function buildAuthRequestHeaders()
    {
        $header = array(
            'context' => array(
                '@attributes' => array(
                    'xmlns' => 'urn:zimbra'
                ),
                'authToken' => array(
                    '@value' => $this->authToken
                )
            )
        );
        return $header;
    }

    /**
     * @return array
     */
    private function buildNoAuthRequestHeaders()
    {
        $header = array(
            'context' => array(
                '@attributes' => array(
                    'xmlns' => 'urn:zimbra'
                )
            )
        );
        return $header;
    }

    public function login()
    {
        $response = $this->request('Auth', array(), array('name' => $this->adminUser, 'password' => $this->adminPass));
        $this->authToken = $response['authToken'];
    }

    public function countAccount($domain, $by = 'name')
    {
        $response = $this->request('CountAccount', array(), array(
            'domain' => array(
                '@attributes' => array(
                    'by' => $by
                ),
                '@value' => $domain
            )
        ));

        $coses = array();
        if (is_array($response)) {
            if (array_key_exists('@attributes', $response['cos'])) {
                $coses[$response['cos']['@attributes']['name']] = array('count' => $response['cos']['@value'], 'id' => $response['cos']['@attributes']['id']);
            } else {
                foreach ($response['cos'] as $cos) {
                    $coses[$cos['@attributes']['name']] = array('count' => $cos['@value'], 'id' => $cos['@attributes']['id']);
                }
            }
        }

        return $coses;
    }

    public function createDomain($domain, $attributes, $vhosts = array())
    {
        $a = $this->getAArray($attributes);
        foreach ($vhosts as $vhost) {
            $a[] = array('@attributes' => array('n' => 'zimbraVirtualHostname'), '@value' => $vhost);
        }
        $response = $this->request('CreateDomain', array('name' => $domain), array('a' => $a));

        return $response['domain']['@attributes']['id'];
    }

    private function getAArray($attributes, $n = 'n')
    {
        $a = array();
        foreach ($attributes as $key => $value) {
            $a[] = array(
                '@attributes' => array(
                    $n => $key
                ),
                '@value' => $value
            );
        }

        return $a;
    }

    public function deleteDomain($id)
    {
        $this->request('DeleteDomain', array('id' => $id));
    }

    public function getDomainId($name)
    {
        $response = $this->request('GetDomain', array(), array(
            'domain' => array(
                '@attributes' => array(
                    'by' => 'name'
                ),
                '@value' => $name,
            )
        ));

        return $response['domain']['@attributes']['id'];
    }

    public function getDomain($name)
    {
        $response = $this->request('GetDomain', array(), array(
            'domain' => array(
                '@attributes' => array(
                    'by' => 'name'
                ),
                '@value' => $name,
            )
        ));

        $domain = array();
        $domain['id'] = $response['domain']['@attributes']['id'];
        foreach ($response['domain']['a'] as $a) {
            $key = $a['@attributes']['n'];
            if (array_key_exists($key, $domain)) {
                if (is_array($domain[$key])) {
                    $domain[$key][] = $a['@value'];
                } else {
                    $domain[$key] = array($domain[$key], $a['@value']);
                }
            } else {
                $domain[$key] = $a['@value'];
            }
        }

        return $domain;
    }

    public function modifyDomain($id, $attributes)
    {
        $a =  $this->getAArray($attributes);
        $this->request('ModifyDomain', array(), array(
            'id' => $id,
            'a' => $a
        ));
    }

    public function createAccount($name, $password, $attributes, &$returnAttributes = array())
    {
        $a = $this->getAArray($attributes);
        $response = $this->request('CreateAccount', array(), array(
            'name' => $name,
            'password' => $password,
            'a' => $a
        ));

        $returnAttributes = array();
        foreach ($response['account']['a'] as $node) {
            $returnAttributes[$node['@attributes']['n']] = $node['@value'];
        }

        return $response['account']['@attributes']['id'];
    }

    public function getAccountCosId($name)
    {
        $response = $this->request('GetAccountInfo', array(), array(
            'account' => array(
                '@attributes' => array(
                    'by' => 'name'
                ),
                '@value' => $name
            )
        ));

        return $response['cos']['@attributes']['id'];
    }

    public function getAccount($name)
    {
        $response = $this->request('GetAccount', array(), array(
            'account' => array(
                '@attributes' => array(
                    'by' => 'name'
                ),
                '@value' => $name
            )
        ));

        $account = array();
        $account['id'] = $response['account']['@attributes']['id'];
        foreach ($response['account']['a'] as $a) {
            $account[$a['@attributes']['n']] = $a['@value'];
        }

        return $account;
    }

    public function getAccounts($domainName)
    {
        $response = $this->request('GetAllAccounts', array(), array(
            'domain' => array(
                '@attributes' => array(
                    'by' => 'name'
                ),
                '@value' => $domainName
            )
        ));

        $accounts = array();
        if (array_key_exists('a', $response['account'])) {
            //single mailbox
            $response['account'] = array($response['account']);
        }
        foreach ($response['account'] as $acc) {
            $account = array();
            foreach ($acc['a'] as $a) {
                $key = $a['@attributes']['n'];
                if (array_key_exists($key, $account)) {
                    if (is_array($account[$key])) {
                        $account[$key][] = $a['@value'];
                    } else {
                        $account[$key] = array($account[$key], $a['@value']);
                    }
                } else {
                    $account[$key] = $a['@value'];
                }
            }
            $accounts[$acc['@attributes']['name']] = $account;
        }

        return $accounts;
    }

    public function getDls($domainName)
    {
        $response = $this->request('GetDistributionList', array(), array(
            'domain' => array(
                '@attributes' => array(
                    'by' => 'name'
                ),
                '@value' => $domainName
            )
        ));

        $accounts = array();
        if (array_key_exists('a', $response['dl'])) {
            //single mailbox
            $response['dl'] = array($response['dl']);
        }
        foreach ($response['dl'] as $acc) {
            $account = array();
            foreach ($acc['a'] as $a) {
                $key = $a['@attributes']['n'];
                if (array_key_exists($key, $account)) {
                    if (is_array($account[$key])) {
                        $account[$key][] = $a['@value'];
                    } else {
                        $account[$key] = array($account[$key], $a['@value']);
                    }
                } else {
                    $account[$key] = $a['@value'];
                }
            }
            $accounts[$acc['@attributes']['name']] = $account;
        }

        return $accounts;
    }

    public function deleteAccount($id)
    {
        $this->request('DeleteAccount', array('id' => $id));
    }

    public function modifyAccount($id, $attributes)
    {
        $a = $this->getAArray($attributes);
        $response = $this->request('ModifyAccount', array(), array(
            'id' => $id,
            'a' => $a
        ));

        return $response;
    }

    public function renameAccount($id, $newAddress)
    {
        $response = $this->request('RenameAccount', array(
            'newName' => $newAddress,
            'id' => $id
        ));

        return $response;
    }

    public function getAccountId($name)
    {
        $response = $this->request('GetAccount', array(), array(
            'account' => array(
                '@attributes' => array(
                    'by' => 'name'
                ),
                '@value' => $name
            )
        ));

        return $response['account']['@attributes']['id'];
    }

    public function addAccountAlias($id, $alias, $attributes = array())
    {
        $a = $this->getAArray($attributes);
        $this->request('AddAccountAlias', array(), array(
            'id' => $id,
            'alias' => $alias,
            'a' => $a
        ));
    }

    public function removeAccountAlias($id, $alias)
    {
        $this->request('RemoveAccountAlias', array(), array(
            'id' => $id,
            'alias' => $alias
        ));
    }

    public function getDlId($name)
    {
        $response = $this->request('GetDistributionList', array(), array(
            'dl' => array(
                '@attributes' => array(
                    'by' => 'name'
                ),
                '@value' => $name
            )
        ));

        return $response['dl']['@attributes']['id'];
    }

    public function addDlMember($id, $member)
    {
        $this->request('AddDistributionListMember', array(), array(
            'id' => $id,
            'dlm' => $member
        ));
    }

    public function removeDlMember($id, $member)
    {
        $this->request('RemoveDistributionListMember', array(), array(
            'id' => $id,
            'member' => $member
        ));
    }

    public function createDl($name, $attributes, $views)
    {
        $a = $this->getAArray($attributes);
        foreach ($views as $view) {
            $a[] = array(
                '@attributes' => array(
                    'n' => 'zimbraAdminConsoleUIComponents'
                ),
                '@value' => $view
            );
        }
        $response = $this->request('CreateDistributionList', array(), array(
            'name' => array(
                '@value' => $name
            ),
            'a' => $a
        ));


        return $response['dl']['@attributes']['id'];
    }

    public function deleteDl($id)
    {
        $this->request('DeleteDistributionList', array(), array(
            'id' => $id
        ));
    }

    public function getCosId($name)
    {
        $response = $this->request('GetCos', array(), array(
            'cos' => array(
                '@attributes' => array(
                    'by' => 'name'
                ),
                '@value' => $name
            )
        ));

        return $response['cos']['@attributes']['id'];
    }

    public function getAllCoses()
    {
        $response = $this->request('GetAllCos', array(), array());

        $coses = array();
        foreach ($response['cos'] as $cos) {
            $coses[] = array('id' => $cos['@attributes']['id'], 'name' => $cos['@attributes']['name']);
        }

        return $coses;
    }

    public function grantRight($target, $targetType, $grantee, $granteeType, $right, $deny = 0)
    {
        $response = $this->request('GrantRight', array(), array(
            'target' => array(
                '@attributes' => array(
                    'by' => 'name',
                    'type' => $targetType
                ),
                '@value' => $target
            ),
            'grantee' => array(
                '@attributes' => array(
                    'by' => 'name',
                    'type' => $granteeType
                ),
                '@value' => $grantee
            ),
            'right' => array(
                '@attributes' => array(
                    'deny' => $deny
                ),
                '@value' => $right
            )
        ));

        return $response;
    }

    public function revokeRight($target, $targetType, $grantee, $granteeType, $right, $deny = 0)
    {
        $response = $this->request('RevokeRight', array(), array(
            'target' => array(
                '@attributes' => array(
                    'by' => 'name',
                    'type' => $targetType
                ),
                '@value' => $target
            ),
            'grantee' => array(
                '@attributes' => array(
                    'by' => 'name',
                    'type' => $granteeType
                ),
                '@value' => $grantee
            ),
            'right' => array(
                '@attributes' => array(
                    'deny' => $deny
                ),
                '@value' => $right
            )
        ));

        return $response;
    }

    public function enableArchive($account, $archiveAccount, $cos, $archive = true)
    {
        $response = $this->request('EnableArchive', array(), array(
            'account' => array(
                '@attributes' => array(
                    'by' => 'name',
                ),
                '@value' => $account
            ),
            'archive' => array(
                '@attributes' => array(
                    'create' => $archive ? '1' : '0',
                ),
                'name' => array(
                    '@value' => $archiveAccount
                ),
                'cos' => array(
                    '@attributes' => array(
                        'by' => 'name',
                    ),
                    '@value' => $cos
                )
            )
        ));

        return $response;
    }

    public function delegateAuth($account)
    {
        if ($this->delegatedAuthAccount != $account) {
            $response = $this->request('DelegateAuth', array(), array(
                'account' => array(
                    '@attributes' => array(
                        'by' => 'name'
                    ),
                    '@value' => $account
                )
            ));

            $this->delegatedAuthToken = $response['authToken'];
            $this->delegatedAuthAccount = $account;

            return $response;
        }

        return false;
    }

    public function addArchiveReadFilterRule($account)
    {
        $this->delegateAuth($account);

        $response = $this->request('ModifyFilterRules', array(), array(
            'filterRules' => array(
                'filterRule' => array(
                    '@attributes' => array(
                        'name' => 'Archive_Read',
                        'active' => '1'
                    ),
                    'filterTests' => array(
                        '@attributes' => array(
                            'condition' => 'anyof'
                        ),
                        'headerTest' => array(
                            '@attributes' => array(
                                'index' => '0',
                                'caseSensitive' => '0',
                                'value' => '*',
                                'negative' => '0',
                                'stringComparison' => 'matches',
                                'header' => 'from'
                            )
                        )
                    ),
                    'filterActions' => array(
                        'actionFlag' => array(
                            '@attributes' => array(
                                'index' => 0,
                                'flagName' => 'read'
                            )
                        )
                    )
                )
            )
        ), true);

        return $response;
    }

    public function folderGrantAction($account, $folderId, $grantee, $permission)
    {
        $this->delegateAuth($account);

        $response = $this->request('FolderAction', array(), array(
            'action' => array(
                '@attributes' => array(
                    'id' => $folderId,
                    'op' => 'grant'
                ),
                'grant' => array(
                    '@attributes' => array(
                        'd' => $grantee,
                        'gt' => 'usr',
                        'perm' => $permission
                    )
                )
            )
        ), true);

        return $response;
    }

    public function getFolder($account, $folderId)
    {
        $this->delegateAuth($account);

        $response = $this->request('GetFolder', array(), array(
            'folder' => array(
                '@attributes' => array(
                    'l' => $folderId
                )
            )
        ), true);

        return $response;
    }

    public function getFolders($accountName)
    {
        $this->delegateAuth($accountName);

        $response = $this->request('GetFolder', array(), array(), true);

        return $response;
    }

    public function createMountPoint($account, $reminder, $name, $path, $owner, $view)
    {
        $this->delegateAuth($account);

        $response = $this->request('CreateMountpoint', array(), array(
            'link' => array(
                '@attributes' => array(
                    'reminder' => $reminder,
                    'name' => $name,
                    'path' => $path,
                    'owner' => $owner,
                    'view' => $view,
                    'l' => 1
                )
            )
        ), true);

        return $response;
    }

    public function disableArchive($account)
    {
        $response = $this->request('DisableArchive', array(), array(
            'account' => array(
                '@attributes' => array(
                    'by' => 'name'
                ),
                '@value' => $account
            )
        ));

        return $response;
    }

    public function createGalSyncAccount($account, $domain)
    {
        $response = $this->request('CreateGalSyncAccount', array(
            'name' => 'InternalGAL',
            'domain' => $domain,
            'type' => 'zimbra',
            'folder' => '_InternalGal'
        ), array(
            'account' => array(
                '@attributes' => array(
                    'by' => 'name'
                ),
                '@value' => $account
            )
        ));

        return $response;
    }

    public function createAliasDomain($alias, $domainId)
    {
        $a = $this->getAArray(array('zimbraDomainType' => 'alias', 'zimbraDomainAliasTargetId' => $domainId));
        $response = $this->request('CreateDomain', array(),
            array(
                'domain' => array(
                    '@attributes' => array(
                        'name' => $alias
                    ),
                    'a' => $a
                )
            )
        );

        return $response['domain']['@attributes']['id'];
    }

    public function getInfo($account)
    {
        $this->delegateAuth($account);

        return $this->request('GetInfo', array(), array(), true, 'Account');
    }

    public function getAccountQuotaUsed($name)
    {
        $info = $this->getInfo($name);
        $used = $info['used'];
        $quota = 'unknown';
        foreach ($info['attrs']['attr'] as $a) {
            if ($a['@attributes']['name'] == 'zimbraMailQuota') {
                $quota = $a['@value'];
            }
        }

        return $used . '/' . $quota;
    }

    public function getFolderByName($accountName, $folderName)
    {
        $folders = $this->getFolders($accountName);

        foreach ($folders['folder']['folder'] as $folder) {
            if ($folder['@attributes']['name'] == $folderName) {

                return $folder;
            }
        }

        return false;
    }

    public function createFolder($accountName, $folderName, $parentFolderId)
    {
        if (strpos($folderName, '/') > -1) {

            throw new \InvalidArgumentException("Invalid folder name, $folderName, folder names cannot contain forward slash charaters. You must provide the parent folder ID to create a subfolder.");
        }

        $this->delegateAuth($accountName);

        $response = $this->request('CreateFolder', array(),
            array(
                'folder' => array(
                    '@attributes' => array(
                        'l' => $parentFolderId,
                        'name' => $folderName
                    )
                )
            ),
            true
        );

        return $response['folder']['@attributes']['id'];
    }

    public function createContact($accountName, $attr, $contactsFolderId = null)
    {
        if (is_null($contactsFolderId)) {
            $contactsFolder = $this->getFolderByName($accountName, 'Contacts');
            if (!$contactsFolder) {

                throw new SoapFaultException('Contacts folder not found on ' . $accountName);
            }
            $contactsFolderId = $contactsFolder['@attributes']['id'];
        } else {
            $this->delegateAuth($accountName);
        }

        $response = $this->request('CreateContact', array(),
            array(
                'cn' => array(
                    '@attributes' => array(
                        'l' => $contactsFolderId
                    ),
                    'a' => $this->getAArray($attr)
                ),
            ),
            true
        );

        return $response['cn']['@attributes']['id'];
    }

    public function createSignature($accountName, $sigName, $sigType, $sigContent)
    {
        $this->delegateAuth($accountName);

        $response = $this->request('CreateSignature', array(),
            array(
                'signature' => array(
                    '@attributes' => array(
                        'name' => $sigName,
                    ),
                    'content' => array(
                        '@attributes' => array(
                            'type' => $sigType
                        ),
                        '@value' => $sigContent
                    )
                )
            ),
            true, 'Account'
        );

        return $response['signature']['@attributes']['id'];

    }

    public function getAllTags($accountName)
    {
        $this->delegateAuth($accountName);

        $response = $this->request('GetTag', array(), array(), true);

        $tags = array();
        //single
        if (array_key_exists('tag', $response)) {
            if (array_key_exists('@attributes', $response['tag'])) {
                //single
                $tags[] = $response['tag']['@attributes'];
            } else {
                //multiple
                foreach ($response['tag'] as $tag) {
                    $tags[] = $tag['@attributes'];
                }
            }
        }

        return $tags;
    }

    public function createTag($accountName, $tagName)
    {
        $this->delegateAuth($accountName);

        $response = $this->request('CreateTag', array(),
            array(
                'tag' => array(
                    '@attributes' => array(
                        'name' => $tagName
                    )
                )
            ),
            true
        );

        return $response['tag']['@attributes']['id'];
    }

    public function tagContact($accountName, $contactId, $tagId)
    {
        $this->delegateAuth($accountName);

        $this->request('ContactAction', array(),
            array(
                'action' => array(
                    '@attributes' => array(
                        'op' => 'tag',
                        'id' => $contactId,
                        'by' => 'name',
                        'tag' => $tagId
                    )
                )
            ),
            true
        );
    }

    public function setPassword($accountId, $newPassword)
    {
        $this->request('SetPassword', array(
            'newPassword' => $newPassword,
            'id' => $accountId
        ));
    }

    public function createIdentity($accountName, $name, $fromAddress, $fromDisplay)
    {
        $this->delegateAuth($accountName);
        $aArray = $this->getAArray(
            array(
                'zimbraPrefFromAddress' => $fromAddress,
                'zimbraPrefFromDisplay' => $fromDisplay,
                '
                        zimbraPrefFromAddressType' => 'sendAs'
            ),
            'name'
        );
        $this->request('CreateIdentity', array(), array(
            'identity' => array(
                '@attributes' => array(
                    'name' => $name,
                ),
                'a' => $aArray,
            )
        ), true, 'Account');
    }
}