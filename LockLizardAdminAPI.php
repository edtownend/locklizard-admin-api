<?php

namespace LockLizardAdminAPI;

/**
 * Class LockLizardAdminAPI
 * Wrapper for the LockLizard Enterprise v4 Admin
 * https://github.com/edtownend/locklizard-admin-api
 * @author  Edward Townend <edward@townend.co>
 */

Class LockLizardAdminAPI {

    /**
     * The LockLizard Admin server base URL without trailing slash
     * @var string
     */
    private $serverUrl;

    /**
     * LockLizard Admin server username
     * @var string
     */
    private $username;

    /**
     * LockLizard Admin server password
     * @var string
     */
    private $password;

    /**
     * Timeout in seconds for API requests
     * @var integer
     */
    private $timeout = 15;

    /**
     * Timezone used for DateTime objects
     * @var DateTimeZone
     */
    private $timezone;

    /**
     * These parameters have maximum input
     * and the request will be chunked if those limits are exceeded
     * @var array
     */
    private $chunkable = [
        'custid',
        'publication',
        'document',
        'pubid',
        'docid',
    ];

    /**
     * The maximum number of IDs in each chunkable param
     * In V4 the limit is actually 200 IDs, BUT that's in total over all the chunkable params. BAH
     * TODO: more intelligently chunk to meet this limit
     * @var integer
     */
    private $chunkSize = 100;

    /**
     * Keys for matching customer data to values
     * Only included the keys that are in every customer
     * request, have to add some on for some requests
     * @var array
     */
    private $customerParams = array(
        'id',
        'name',
        'email',
        'company_name',
        'valid_from',
        'expires_at',
        'licenses',
        'active',
        'registered',
    );

    /**
     * Keys for matching document data to values
     * @var array
     */
    private $documentParams = array(
        'id',
        'title',
        'published_at',
        'expires_at',
        'protection_type',
        'web_viewer',
    );

    /**
     * Keys for matching customer/document access data to values
     * @var array
     */
    private $customerDocumentParams = array(
        'document_id',
        'customer_id',
        'customer_name_and_email',
        'customer_company_name',
        'timestamp',
    );

    /**
     * Called on instantiation
     * @param string $serverUrl Base URL for admin server
     * @param string $username  Username for admin server
     * @param string $password  Password for admin server
     */
    function __construct($serverUrl, $username, $password) {

        // Strip trailing slash from url
        $this->serverUrl = preg_replace('/\/$/', '', $serverUrl);
        $this->username = $username;
        $this->password = $password;

        // LockLizard API always returns times in GMT
        $this->timezone = new DateTimeZone('GMT');

        return $this;
    }

    /**
     * Build the URL for an admin request from an action
     * @param  string $action API action to perform
     * @param  array  $extra  Additional GET arguments
     * @return string         The full URL for the request
     */
    private function buildUrl($action, $extra = array())
    {
        // http_build_query handles urlencoding
        $params = array(
            'un' => $this->username,
            'pw' => $this->password,
            'action' => $action,
        );

        $params = array_merge($params, $extra);

        $queryString = http_build_query($params);

        return $this->serverUrl . '/Interop.php' . '?' . $queryString;
    }

    /**
     * Send an API request, chunking as necessary
     * @param  string $url  URL to POST to
     * @param  array  $data Request body
     * @return string response body
     */
    private function send($url, $data = array())
    {
        // check for chunkable parameters
        $chunked = array();
        foreach ( $this->chunkable as $key ) {
            if ( array_key_exists($key, $data) ) {

                $exploded = explode(',', $data[$key]);

                if (count($exploded) > $this->chunkSize) {
                    $chunked[$key] = array_chunk($exploded, $this->chunkSize);
                    unset($data[$key]);
                }
            }
        }

        if ( empty($chunked) ) {
            return $this->sendPost($url, $data);

        } elseif ( count($chunked) === 1 ) {

            $key = key($chunked);

            foreach ( $chunked[$key] as $chunk ) {
                $thisData = $data;
                $thisData[$key] = implode(',', $chunk);

                $res = $this->sendPost($url, $thisData);

                $out = $this->parseData($res);

                if ( $out['status'] !== 'OK' ) {
                    // abort!
                    return $res;
                }
            }

            return $res;

        } else {

            foreach ( $chunked as $key1 => $chunks1 ) {
                // avoid same key appearing in inner array
                unset($chunked[$key1]);
                foreach ( $chunks1 as $chunk1 ) {
                    foreach ( $chunked as $key2 => $chunks2 ) {
                        foreach ( $chunks2 as $chunk2 ) {
                            $thisData = $data;
                            $thisData[$key1] = implode(',', $chunk1);
                            $thisData[$key2] = implode(',', $chunk2);

                            $res = $this->sendPost($url, $thisData);

                            $out = $this->parseData($res);

                            if ( $out['status'] !== 'OK' ) {
                                // abort!
                                return $res;
                            }
                        }
                    }
                }
            }

            return $res;
        }
    }

    /**
     * Send a POST request
     * @param  string $url  URL to POST to
     * @param  array  $data Request body
     * @return string response body
     */
    private function sendPost($url, $data = array())
    {
        // Use native WordPress functionality if found
        if ( function_exists('wp_remote_post') ) {
            $response = wp_remote_post($url, array(
                'body' => $data,
                'timeout' => $this->timeout,
            ));

            if ( is_wp_error( $response ) ) {
                throw new Exception($response->get_error_message());
            }

            return $response['body'];
        }

        // Curl fallback
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $response = curl_exec($curl);
        curl_close($curl);

        if ($response) {
            return $response;
        } else {
            throw new Exception(curl_error($curl));
        }
    }

    /**
     * Parse the status and response data returned from LockLizard
     * @param  string $data raw response body
     * @return array
     */
    private function parseData($data)
    {
        // split by line
        $data = preg_split('/\n|\r/', $data, -1, PREG_SPLIT_NO_EMPTY);

        $output = [];

        $output['status'] = array_shift($data);

        $output['data'] = $data;

        return $output;
    }

    /**
     * Parse data arrays from an array of admin response lines.
     * Recognises and correctly formats dates, timestamps and boolean values
     * @param  array $lines Each line from the admin response as an array item
     * @return array        Array of arrays
     */
    private function parseArraysFromLines($lines)
    {
        foreach ($lines as $i => $line) {
            $line = explode('" "', $line);

            // Standardise formats
            $line = array_map(function($item){
                $val = preg_replace('/^"|"$/', '', $item);

                if ( $val === 'true' || $val === 'yes' ) {
                    return true;

                } elseif ( $val === 'false' || $val === 'no' ) {
                    return false;

                } elseif ( preg_match("/^[0-9]{2}-[0-9]{2}-[0-9]{4}$/", $val) ) {
                    // Dates
                    return DateTime::createFromFormat('m-d-Y', $val, $this->timezone);

                } elseif ( preg_match("/^[0-9]{2}-[0-9]{2}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2}$/", $val) ) {
                    // Timestamps
                    return DateTime::createFromFormat('m-d-Y H:i:s', $val, $this->timezone);

                } else {
                    return stripslashes($val);

                }

            }, $line);

            $lines[$i] = $line;
        }

        return $lines;
    }

    /****************************
    PUBLIC METHODS
    ****************************/

    /**
     * Set the timeout for API requests
     * @param int $value timeout in seconds
     */
    public function setTimeout($value)
    {
        $this->timeout = $value;
    }

    /**
     * Get a list of all customers
     * @param  boolean $webOnly list only customers with Web Viewer access
     * @param  boolean $pdcOnly list only customers without Web Viewer access
     * @return array
     */
    public function listCustomers($webOnly = false, $pdcOnly = false)
    {
        $url = $this->buildUrl('list_customers');

        $response = $this->send($url, array(
            'webonly' => $webOnly,
            'pdconly' => $pdcOnly,
        ));

        $output = $this->parseData($response);

        if ($output['status'] === 'OK') {

            $data = $this->parseArraysFromLines($output['data']);

            foreach ( $data as $i => $line ) {

                $params = $this->customerParams;
                $params[] = 'web_viewer';

                $params = array_slice($params, 0, count($line));

                $data[$i] = array_combine($params, $line);
            }

            $output['data'] = $data;
        }

        return $output;
    }

    /**
     * Get details for a customer by ID or email
     * @param  string $type 'email' or 'id', type of ID to use
     * @param  string $id ID or email
     * @param  bool $noDocs set to true to prevent the listing of document IDs
     * @return array
     */
    public function listCustomer($type, $id, $noDocs = false)
    {
        $url = $this->buildUrl('list_customer');

        $data = array(
            'nodocs' => $noDocs,
        );

        switch ($type) {
            case 'email':
                $data['email'] = $id;
                break;

            default:
                $data['custid'] = $id;
                break;
        }

        $response = $this->send($url, $data);

        $output = $this->parseData($response);

        if ($output['status'] === 'OK') {

            $data = $this->parseArraysFromLines($output['data'])[0];

            $params = $this->customerParams;

            if ( $noDocs ) {
                $params[] = 'web_viewer';
            } else {
                $params[] = 'documents';
                $params[] = 'publications';
                $params[] = 'web_viewer';
            }

            // Slice off web_viewer if the API hasn't returned a value for it
            $params = array_slice($params, 0, count($data));

            $data = array_combine($params, $data);

            if ($data['documents']) {
                // Have to filter because LockLizard adds a trailing comma
                $data['documents'] = array_filter(explode(',', $data['documents']));
            }
            if ($data['publications']) {
                $data['publications'] = array_filter(explode(',', $data['publications']));
            }

            $output['data'] = $data;
        }

        return $output;
    }

    /**
     * Show the documents and publications all customers have access to
     * and whether they have registered or not
     * @param  boolean $webOnly list only customers with Web Viewer access
     * @param  boolean $pdcOnly list only customers without Web Viewer access
     * @return array
     */
    public function listCustomersAccess($webOnly = false, $pdcOnly = false)
    {
        $url = $this->buildUrl('list_customers_access');

        $response = $this->send($url, array(
            'webonly' => $webOnly,
            'pdconly' => $pdcOnly,
        ));

        $output = $this->parseData($response);

        if ($output['status'] === 'OK') {

            $data = $this->parseArraysFromLines($output['data']);

            foreach ( $data as $i => $line ) {

                $params = $this->customerParams;

                $params[] = 'documents';
                $params[] = 'publications';
                $params[] = 'web_viewer';

                // Slice off web_viewer if the API hasn't returned a value for it
                $params = array_slice($params, 0, count($line));

                $data[$i] = array_combine($params, $line);

                if ($data[$i]['documents']) {
                    // Have to filter because LockLizard adds a trailing comma
                    $data[$i]['documents'] = array_filter(explode(',', $data[$i]['documents']));
                }
                if ($data[$i]['publications']) {
                    $data[$i]['publications'] = array_filter(explode(',', $data[$i]['publications']));
                }
            }

            $output['data'] = $data;
        }

        return $output;
    }

    /**
     * This command is used to list the number of customers you have.
     * @param  bool $webonly count only customers with Web Viewer enabled
     * @return array
     */
    public function getCustomersCount($webOnly = false)
    {
        $url = $this->buildUrl('get_customers_count');

        $response = $this->send($url, array(
            'webonly' => $webOnly
        ));

        $output = $this->parseData($response);

        $output['data'] = reset($output['data']);

        return $output;
    }

    /**
     * This command is used to add a new customer to the Administration system.
     * @param string $name Customer name
     * @param string $email Customer email. If already exists, will update the existing customer
     * @param int $licenses Number of licenses. Must be greater than 0
     * @param string $companyName Company name - optional
     * @param string $startDate Date the account becomes valid from. Optional. MM-DD-YYYY
     * @param string $endDate Date the account stops being valid from. Optional. MM-DD-YYYY
     * @param array $publicationIds array of publication IDs to grant access to
     * @param bool $noRegEmail Suppress the registration email
     * @param bool $webViewer Permit Web Viewer access
     */
    public function addCustomer(
        $name,
        $email,
        $licenses,
        $companyName = '',
        $startDate = '',
        $endDate = '',
        $publicationIds = array(),
        $noRegEmail = true,
        $webViewer = false
    ) {
        $url = $this->buildUrl('add_customer');

        if ( ! $startDate ) {
            $startDate = date('m-d-Y');
        }

        $data = array(
            'name' => $name,
            'email' => $email,
            'company' => $companyName,
            'start_date' => $startDate,
            'licenses' => $licenses,
            'noregemail' => $noRegEmail,
            'webviewer' => $webViewer,
        );

        if ( ! empty($publicationIds) ) {
            $data['publication'] = implode(',', $publicationIds);
        }

        if ( $startDate && $endDate ) {
            $data['end_type'] = 'date';
            $data['end_date'] = $endDate;
        } else {
            $data['end_type'] = 'unlimited';
        }

        $response = $this->send($url, $data);

        $output = $this->parseData($response);


        if ($output['status'] === 'OK') {

            $data = $this->parseArraysFromLines((array)$output['data']);

            if ( $webViewer ) {
                $data = array(
                    'id' => $data[0][0],
                    'username' => $data[1][0],
                    'password' => $data[2][0],
                );

            } else {
                $data = array(
                    'id' => $data[0][0],
                );
            }

            $output['data'] = $data;

        }

        return $output;
    }

    /**
     * Suspend a customer account.
     * @param  int $customerId customer ID to suspend
     * @return array
     */
    public function suspendCustomer($customerId)
    {
        $url = $this->buildUrl('suspend_customer');

        $response = $this->send($url, array('custid' => $customerId));

        $output = $this->parseData($response);

        $output['data'] = reset($output['data']);

        return $output;
    }

    /**
     * Enable a customer account.
     * @param  int $customerId customer ID to enable
     * @return array
     */
    public function enableCustomer($customerId)
    {
        $url = $this->buildUrl('enable_customer');

        $response = $this->send($url, array('custid' => $customerId));

        $output = $this->parseData($response);

        $output['data'] = reset($output['data']);

        return $output;
    }

    /**
     * This command is used to update the start and expiry dates of a customer’s account.
     * @param  int    $customerId    ID of the customer to update
     * @param  string $startDate  Optional, leave blank to not change Format: MM-DD-YY
     * @param  string $endType    'date' or 'unlimited'
     * @param  string $endDate    Required if end_type is set to 'date' Format MM-DD-YY
     * @return array
     */
    public function updateCustomerAccountValidity(
        $customerId,
        $startDate = '',
        $endType = 'unlimited',
        $endDate = ''
    ) {
        $url = $this->buildUrl('update_customer_account_validity');

        $response = $this->send($url, [
            'custid' => $customerId,
            'start_date' => $startDate,
            'end_type' => $endType,
            'end_date' => $endDate,
        ]);

        $output = $this->parseData($response);

        $output['data'] = reset($output['data']);

        return $output;
    }

    /**
     * Set customer license count
     * @param  int $customerId ID of the customer to update
     * @param  int $licenses number of licenses to grant.
     * 0 removes all licenses from an account.
     * @return array
     */
    public function setCustomerLicenseCount($customerId, $licenses)
    {
        $url = $this->buildUrl('set_customer_license_count');

        $response = $this->send($url, array(
            'custid' => $customerId,
            'licenses' => $licenses
        ));

        $output = $this->parseData($response);

        $output['data'] = reset($output['data']);

        return $output;
    }

    /**
     * Update customer license count - Adds x to available licenses
     * @param  int $customerId ID of the customer to update
     * @param  int $licenses number of licenses to increment by.
     * @return array
     */
    public function updateCustomerLicenseCount($customerId, $licenses)
    {
        $url = $this->buildUrl('update_customer_license_count');

        $response = $this->send($url, array(
            'custid' => $customerId,
            'licenses' => $licenses
        ));

        $output = $this->parseData($response);

        $output['data'] = reset($output['data']);

        return $output;
    }

    /**
     * Get the license file for a customer
     * @param  int  $customerId ID of the customer
     * @param  boolean $link return a link to the file instead of the file body
     * @return array
     */
    public function getCustomerLicense($customerId, $link = false)
    {
        $url = $this->buildUrl('get_customer_license');

        $response = $this->send($url, array(
            'custid' => $customerId,
            'link' => $link
        ));

        $output = $this->parseData($response);

        // If successful, the API breaks convention in not returning a status
        // so we'll revert back to the standard
        if ($output['status'] === 'Failed') {
            return $output;

        } else {
            return array(
                'status' => 'OK',
                'data' => $response,
            );
        }
    }

    /**
     * Grant or deny access to the Web Viewer for an existing user
     * or to change their Web Viewer login credentials.
     * @param int  $customerId ID of the customer to update
     * @param bool  $permit set true to permit access to WebViewer
     * @param string  $username   Optional if account previously had Web Viewer access
     * @param string  $password   Optional if account previously had Web Viewer access
     * @param boolean $noEmail    Suppress the registration email
     * @return array
     */
    public function setCustomerWebViewerAccess(
        $customerId,
        $permit,
        $username = '',
        $password = '',
        $noEmail = true
    ) {
        $url = $this->buildUrl('set_customer_webviewer_access');

        $response = $this->send($url, array(
            'custid' => $customerId,
            'webviewer' => $permit,
            'username' => $username,
            'password' => $password,
            'noregemail' => $noEmail,
        ));

        $output = $this->parseData($response);

        if ($output['status'] === 'OK') {

            $data = $this->parseArraysFromLines($output['data']);

            $data = array_combine(array(
                'username',
                'password'
            ), $data);

            $output['data'] = $data;
        }

        return $output;
    }

    /**
     * Get the Web Viewer access status for a customer
     * @param  int $customerId customer ID to retrieve access status for
     * @return array
     */
    public function getCustomerWebViewerAccess($customerId)
    {
        $url = $this->buildUrl('get_customer_webviewer_access');

        $response = $this->send($url, array('custid' => $customerId));

        $output = $this->parseData($response);

        if ($output['status'] === 'OK') {

            $data = $this->parseArraysFromLines($output['data']);

            $data = array_combine(array(
                'has_access',
                'username',
                'password'
            ), $data);

            $output['data'] = $data;
        }

        return $output;
    }

    /**
     * List the documents the customer(s) has viewed
     * Note: this command is only relevant for documents that have view logging enabled.
     * @param  int/array of ints $customerIds IDs of the customers to retrieve view counts for
     * @param  int/array of ints $documentIds Optional document IDs to constrain to
     * @return array
     */
    public function listCustomerViews($customerIds, $documentIds = '')
    {
        $url = $this->buildUrl('list_views');

        if ( is_array($customerIds) ) {
            $customerIds = implode(',', $customerIds);
        }

        if ( is_array($documentIds) ) {
            $documentIds = implode(',', $documentIds);
        }

        $response = $this->send($url, array(
            'custid' => $customerIds,
            'docid' => $documentIds,
        ));

        $output = $this->parseData($response);

        if ($output['status'] === 'OK') {

            $data = $this->parseArraysFromLines($output['data']);

            foreach ( $data as $i => $line ) {
                $data[$i] = array_combine($this->customerDocumentParams, $line);
            }

            $output['data'] = $data;
        }

        return $output;
    }

    /**
     * Update the number of views the customer has available
     * @param  int $customerId ID of the customer to update
     * @param  int $documentId ID of the document to update
     * @param  int $views      Number of views available
     * @return array
     */
    public function updateCustomerViews($customerId, $documentId, $views)
    {
        $url = $this->buildUrl('update_views');

        $response = $this->send($url, array(
            'custid' => $customerId,
            'docid' => $documentId,
            'views' => $views,
        ));

        $output = $this->parseData($response);

        $output['data'] = reset($output['data']);

        return $output;
    }

    /**
     * List the documents the customer(s) has printed
     * Note: this command is only relevant for documents that have print logging enabled.
     * @param  int/array of ints $customerIds IDs of the customers to retrieve print counts for
     * @param  int/array of ints $documentIds Optional document IDs to constrain to
     * @return array
     */
    public function listCustomerPrints($customerIds, $documentIds = '')
    {
        $url = $this->buildUrl('list_prints');

        if ( is_array($customerIds) ) {
            $customerIds = implode(',', $customerIds);
        }

        if ( is_array($documentIds) ) {
            $documentIds = implode(',', $documentIds);
        }

        $response = $this->send($url, array(
            'custid' => $customerIds,
            'docid' => $documentIds,
        ));

        $output = $this->parseData($response);

        if ($output['status'] === 'OK') {

            $data = $this->parseArraysFromLines($output['data']);

            foreach ( $data as $i => $line ) {
                $data[$i] = array_combine($this->customerDocumentParams, $line);
            }

            $output['data'] = $data;
        }

        return $output;
    }

    /**
     * Update the number of prints the customer has available
     * @param  int $customerId ID of the customer to update
     * @param  int $documentId ID of the document to update
     * @param  int $prints     Number of prints available
     * @return array
     */
    public function updateCustomerPrints($customerId, $documentId, $prints)
    {
        $url = $this->buildUrl('update_prints');

        $response = $this->send($url, array(
            'custid' => $customerId,
            'docid' => $documentId,
            'prints' => $prints,
        ));

        $output = $this->parseData($response);

        $output['data'] = reset($output['data']);

        return $output;
    }

    /**
     * List the publication IDs and names for all publications
     * @return array
     */
    public function listPublications()
    {
        $url = $this->buildUrl('list_publications');

        $response = $this->send($url);

        $output = $this->parseData($response);

        if ($output['status'] === 'OK') {

            $data = $this->parseArraysFromLines($output['data']);

            foreach ( $data as $i => $line ) {
                $data[$i] = array_combine(array(
                    'id',
                    'name',
                ), $line);
            }

            $output['data'] = $data;
        }

        return $output;
    }

    /**
     * List the customer IDs with access to each publication ID
     * @return array
     */
    public function listPublicationCustomers()
    {
        $url = $this->buildUrl('list_publications_customers');

        $response = $this->send($url);

        $output = $this->parseData($response);

        if ($output['status'] === 'OK') {

            $data = $this->parseArraysFromLines($output['data']);

            foreach ( $data as $i => $line ) {

                $data[$i] = array_combine(array(
                    'publication_id',
                    'customer_id',
                ), $line);
            }

            $output['data'] = $data;
        }

        return $output;
    }


    /**
     * Get the number of publications in the system
     * @return array
     */
    public function getPublicationsCount()
    {
        $url = $this->buildUrl('get_publications_count');

        $response = $this->send($url);

        $output = $this->parseData($response);

        $output['data'] = reset($output['data']);

        return $output;
    }

    /**
     * Add a publication to the Administration system
     * @param  string $name        Name of the publication
     * @param  string $description Optional description for the publication
     * @param  bool $obeyPubDate   Whether the customer account start date is obeyed or not
     * @return array
     */
    public function addPublication(
        $name,
        $description = '',
        $obeyPubDate = false
    ) {
        $url = $this->buildUrl('add_publication');

        $obeyPubDate = ($obeyPubDate == true ? 'yes' : 'no');

        $response = $this->send($url, array(
            'name' => $name,
            'description' => $description,
            'obeypubdate' => $obeyPubDate,
        ));

        $output = $this->parseData($response);

        if ($output['status'] === 'OK') {

            $data = $this->parseArraysFromLines((array)$output['data']);

            $output['data'] = array(
                'id' => $data[0][0],
            );

        }

        return $output;
    }

    /**
     * Grant customer access to a publication
     * @param  int/array of ints $customerIds Customer ID/s to grant access
     * @param  int/array of ints $publicationIds Publication ID/s to grant access
     * @param  string $startDate      Optional. Format: MM-DD-YYYY
     * @param  string $endDate        Optional. Format: MM-DD-YYYY
     * @return array
     */
    public function grantPublicationAccess(
        $customerIds,
        $publicationIds,
        $startDate = '',
        $endDate = ''
    ) {
        $url = $this->buildUrl('grant_publication_access');

        if ( is_array($customerIds) ) {
            $customerIds = implode(',', $customerIds);
        }

        if ( is_array($publicationIds) ) {
            $publicationIds = implode(',', $publicationIds);
        }

        $response = $this->send($url, array(
            'custid' => $customerIds,
            'publication' => $publicationIds,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ));

        $output = $this->parseData($response);

        $output['data'] = reset($output['data']);

        return $output;
    }

    /**
     * Revoke customer access to a publication
     * @param  int/array of ints $customerIds    Customer ID/s to revoke access
     * @param  int/array of ints $publicationIds Publication ID/s to revoke access
     * @return array
     */
    public function revokePublicationAccess($customerIds, $publicationIds)
    {
        $url = $this->buildUrl('revoke_publication_access');

        if ( is_array($customerIds) ) {
            $customerIds = implode(',', $customerIds);
        }

        if ( is_array($publicationIds) ) {
            $publicationIds = implode(',', $publicationIds);
        }

        $response = $this->send($url, array(
            'custid' => $customerIds,
            'publication' => $publicationIds,
        ));

        $output = $this->parseData($response);

        $output['data'] = reset($output['data']);

        return $output;
    }

    /**
     * Set new publication access for a/many customers - replaces existing access rights
     * This may be slow - up to three separate API requests are performed
     * @param  int/array of ints $customerIds    Customer ID/s to set access
     * @param  int/array of ints $publicationIds Publication ID/s to set access. Leave empty to revoke all
     * @return array
     */
    public function setPublicationAccess($customerIds, $publicationIds)
    {
        // Probably quicker to get all publications rather than find the
        // ones that the given customer ids currently have access to
        $allPublications = $this->listPublications();

        if ( $allPublications['status'] !== 'OK' || empty($allPublications['data']) ) {
            return $allPublications;
        }

        $allPublicationIds = array_column($allPublications['data'], 'id');

        $revokeAll = $this->revokePublicationAccess($customerIds, $allPublicationIds);

        if ( ! empty($publicationIds) ) {
            return $this->grantPublicationAccess($customerIds, $publicationIds);
        } else {
            return $revokeAll;
        }
    }

    /**
     * List document IDs matched to document titles
     * @param  boolean $webOnly Only include Web Viewer enabled documents
     * @param  boolean $pdcOnly Exclude Web Viewer enabled documents
     * @return array
     */
    public function listDocuments($webOnly = false, $pdcOnly = false)
    {
        $url = $this->buildUrl('list_documents');

        $response = $this->send($url, array(
            'webonly' => $webOnly,
            'pdconly' => $pdcOnly,
        ));

        $output = $this->parseData($response);

        if ($output['status'] === 'OK') {

            $data = $this->parseArraysFromLines($output['data']);

            foreach ( $data as $i => $line ) {
                $params = array_slice($this->documentParams, 0, count($line));

                $data[$i] = array_combine($params, $line);
            }

            $output['data'] = $data;
        }

        return $output;
    }

    /**
     * Get a document by ID
     * This isn't in the LockLizard API so we have to fake it
     * @param  int $documentId Document ID to get details for
     * @return array
     */
    public function getDocument($documentId)
    {
        $documents = $this->listDocuments();

        if ( $documents['status'] === 'OK' ) {
            foreach ($documents['data'] as $document) {
                if ( $document['id'] === $documentId ) {
                    return array(
                        'status' => 'OK',
                        'data' => $document
                    );
                }
            }

            return array(
                'status' => 'Failed',
                'data' => 'Document not found'
            );
        } else {
            return $documents;
        }
    }

    /**
     * List all documents in a publication
     * @param  int $publicationId Publication ID to list documents for
     * @return array
     */
    public function listPublicationDocuments($publicationId)
    {
        $url = $this->buildUrl('list_publication_documents');

        $response = $this->send($url, array(
            'pubid' => $publicationId,
        ));

        $output = $this->parseData($response);

        if ($output['status'] === 'OK') {

            $data = $this->parseArraysFromLines($output['data']);

            foreach ( $data as $i => $line ) {
                $params = array_slice($this->documentParams, 0, count($line));

                $data[$i] = array_combine($params, $line);
            }

            $output['data'] = $data;
        }

        return $output;
    }

    /**
     * List all documents that are not part of a publication
     * or available automatically to all customers
     * @return array
     */
    public function listDocumentsDirectAccess()
    {
        $url = $this->buildUrl('list_documents_direct_access');

        $response = $this->send($url);

        $output = $this->parseData($response);

        if ($output['status'] === 'OK') {

            $data = $this->parseArraysFromLines($output['data']);

            foreach ( $data as $i => $line ) {
                $data[$i] = array_combine(array(
                    'document_id',
                    'customer_id',
                ), $line);
            }

            $output['data'] = $data;
        }

        return $output;
    }

    /**
     * Get the number of documents in the system
     * @param $webOnly Only count documents published for the Web Viewer
     * @return array
     */
    public function getDocumentsCount($webOnly = false)
    {
        $url = $this->buildUrl('get_documents_count');

        $response = $this->send($url, array(
            'webonly' => $webOnly,
        ));

        $output = $this->parseData($response);

        $output['data'] = reset($output['data']);

        return $output;
    }

    /**
     * Grant customer access to a document/s
     * A start and end date is required to set limited access type,
     * otherwise document will be limited only to the document's
     * expiry settings
     * @param  int    $customerIds Customer IDs to grant access
     * @param  int    $documentIds Document IDs to grant access
     * @param  string $startDate   Optional. Format: MM-DD-YYYY
     * @param  string $endDate   Optional. Format: MM-DD-YYYY
     * @return array
     */
    public function grantDocumentAccess(
        $customerIds,
        $documentIds,
        $startDate = '',
        $endDate = ''
    ) {
        $url = $this->buildUrl('grant_document_access');

        if ( is_array($customerIds) ) {
            $customerIds = implode(',', $customerIds);
        }

        if ( is_array($documentIds) ) {
            $documentIds = implode(',', $documentIds);
        }

        $params = array(
            'custid' => $customerIds,
            'docid' => $documentIds,
        );

        if ( $startDate && $endDate ) {
            $params['access_type'] = 'limited';
            $params['start_date'] = $startDate;
            $params['end_date'] = $endDate;
        } else {
            $params['access_type'] = 'unlimited';
        }

        $response = $this->send($url, $params);

        $output = $this->parseData($response);

        $output['data'] = reset($output['data']);

        return $output;
    }

    /**
     * Revoke customer access to a document
     * @param  int    $customerIds Customer IDs to grant access
     * @param  int    $documentIds Document IDs to grant access
     * @return array
     */
    public function revokeDocumentAccess($customerIds, $documentIds)
    {
        // $url = $this->buildUrl('revoke_document_access');
        // WTF LockLizard?!
        $url = $this->buildUrl('revoke_file_access');

        if ( is_array($customerIds) ) {
            $customerIds = implode(',', $customerIds);
        }

        if ( is_array($documentIds) ) {
            $documentIds = implode(',', $documentIds);
        }

        $params = array(
            'custid' => $customerIds,
            'docid' => $documentIds,
        );

        $response = $this->send($url, $params);

        $output = $this->parseData($response);

        $output['data'] = reset($output['data']);

        return $output;
    }

    /**
     * Set new Document access for a/many customers - replaces existing access rights
     * This may be slow - up to three separate API requests are performed
     * @param  int/array of ints $customerIds    Customer ID/s to set access
     * @param  int/array of ints $documentIds Document ID/s to set access. Leave empty to revoke all
     * @return array
     */
    public function setDocumentAccess($customerIds, $documentIds)
    {
        $allAccess = $this->listDocumentsDirectAccess();

        if ( $allAccess['status'] !== 'OK' ) {
            return $allAccess;
        }

        // Get current individual document access for each customer
        $toRevoke = array_map(function($item) use ($customerIds) {
            if ( in_array($item['customer_id'], (array) $customerIds) ) {
                return $item['document_id'];
            } else {
                return null;
            }
        }, (array) $allAccess['data']);

        $toRevoke = array_filter(array_unique($toRevoke));

        if ( ! empty($toRevoke) ) {
            $revoked = $this->revokeDocumentAccess($customerIds, $toRevoke);
        }

        if ( ! empty($documentIds) ) {
            return $this->grantDocumentAccess($customerIds, $documentIds);
        } else {
            return isset($revoked) ? $revoked : array('status' => 'OK'); //nothing changed so fake an ok response
        }
    }
}
