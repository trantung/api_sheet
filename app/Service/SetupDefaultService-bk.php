<?php

namespace App\Service;

class SetupDefaultService extends Model
{
    public function addDomainToDns($fullDomain)
    {
        $apiToken = getenv('CLOUD_FLARE_API_TOKEN');
        $ip = getenv('SERVER_IP_ADDRESS');
        // $fullDomain = 'tenant123.microgem.io.vn'
        // $fullDomain = $data['full_domain'];
        [$sub, $domain] = $this->extractSubdomainAndDomain($fullDomain, 'microgem.io.vn');
        $zoneId = $this->getZoneIdByDomain($apiToken, $domain);
        if (!$zoneId) {
            throw new Exception("Không tìm thấy Zone ID cho $domain");
        }
        $response = $this->createSubdomainOnCloudflare($apiToken, $zoneId, $sub, $domain, $ip);
        $responseDecode = json_decode($response, true);
        return $responseDecode;
    }

    public function extractSubdomainAndDomain($fullDomain, $domainRoot = 'microgem.io.vn') {
        if (!str_ends_with($fullDomain, $domainRoot)) {
            throw new Exception("Tên miền không khớp với domain chính ($domainRoot)");
        }
        $subdomain = str_replace(".$domainRoot", '', $fullDomain);
        return [$subdomain, $domainRoot];
    }

    public function getZoneIdByDomain($apiToken, $domain) {
        $url = "https://api.cloudflare.com/client/v4/zones?name={$domain}";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$apiToken}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success'] === true && count($data['result']) > 0) {
            return $data['result'][0]['id'];
        }

        return null;
    }

    public function createSubdomainOnCloudflare($apiToken, $zoneId, $subdomain, $domain, $ipAddress) {
        $url = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records";

        $postData = [
            "type"    => "A",
            "name"    => "{$subdomain}.{$domain}",
            "content" => $ipAddress,
            "ttl"     => 1,
            "proxied" => false
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$apiToken}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $response;
    }

    public function addDomainToVirtualHost($domain)
    {
        $projectPath = '/var/www/html/sheetany_blog/dist';
        $this->addApacheVirtualHost($domain, $projectPath);
    }

    public function addApacheVirtualHost($domain, $projectPath)
    {
        $confPath = "/etc/apache2/sites-available/{$domain}.conf";

        $vhostConfig = <<<EOL
            <VirtualHost *:80>
                ServerName {$domain}
                DocumentRoot {$projectPath}

                <Directory {$projectPath}>
                    Options Indexes FollowSymLinks
                    AllowOverride All
                    Require all granted
                </Directory>

                ErrorLog \${APACHE_LOG_DIR}/{$domain}_error.log
                CustomLog \${APACHE_LOG_DIR}/{$domain}_access.log combined
            </VirtualHost>
            EOL;

        file_put_contents($confPath, $vhostConfig);
        echo "Đã tạo file cấu hình: $confPath\n";
        // Enable site
        exec("sudo a2ensite {$domain}.conf", $output1, $code1);
        echo implode("\n", $output1) . "\n";

        // Reload Apache
        exec("sudo systemctl reload apache2", $output2, $code2);
        echo implode("\n", $output2) . "\n";

        if ($code1 === 0 && $code2 === 0) {
            echo "Domain {$domain} đã được kích hoạt và trỏ đến {$projectPath}\n";
        } else {
            echo "Có lỗi xảy ra khi kích hoạt VirtualHost.\n";
        }
    }
}
