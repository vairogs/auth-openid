<?php declare(strict_types = 1);

namespace Vairogs\Component\Auth\OpenID;

use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;
use JsonException;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Vairogs\Component\Auth\OpenID\Contracts\OpenIDUser;
use Vairogs\Component\Auth\OpenID\Contracts\OpenIDUserBuilder;
use Vairogs\Component\Utils\Helper\Http;
use Vairogs\Component\Utils\Helper\Json;
use Vairogs\Component\Utils\Helper\Uri;
use function array_keys;
use function explode;
use function file_get_contents;
use function http_build_query;
use function is_array;
use function preg_match;
use function sprintf;
use function str_replace;
use function stream_context_create;
use function stripslashes;
use function strlen;
use function urldecode;

class OpenIDProvider
{
    protected ?Request $request;
    protected ?string $profileUrl;

    /**
     * @param RequestStack $requestStack
     * @param RouterInterface $router
     * @param string $name
     * @param string $cacheDir
     * @param array $options
     */
    public function __construct(RequestStack $requestStack, protected RouterInterface $router, protected string $name, protected string $cacheDir, protected array $options = [])
    {
        $this->request = $requestStack->getCurrentRequest();
        $this->profileUrl = $this->options['provider_options']['profile_url'] ?? null;
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return OpenIDUser|null
     * @throws JsonException
     */
    public function fetchUser(): ?OpenIDUser
    {
        $user = $this->validate();

        if ($user !== null) {
            $builderClass = $this->options['user_builder'];
            /** @var OpenIDUserBuilder $builder */
            $builder = new $builderClass();
            if (null !== ($userClass = $this->options['user_class'] ?? null)) {
                $builder->setUserClass($userClass);
            }
            /** @var OpenIDUserBuilder $builder */
            if (null === $this->profileUrl) {
                $user = $builder->getUser($this->request->query->all());
            } else {
                foreach ($this->options['provider_options']['profile_url_replace'] as $option) {
                    if (null !== ($replace = $this->options[$option] ?? $this->options['provider_options'][$option] ?? null)) {
                        $this->profileUrl = str_replace(sprintf('#%s#', $option), $replace, $this->profileUrl);
                    }
                }

                $data = $this->getData($user);
                $data['cache_dir'] = $this->cacheDir;
                $user = $builder->getUser($data);
            }
        }
        if ($user === null) {
            throw new RuntimeException('error_oauth_login_invalid_or_timed_out');
        }

        return $user;
    }

    /**
     * @param int $timeout
     *
     * @return string|null
     */
    public function validate(int $timeout = 30): ?string
    {
        $get = $this->request->query->all();
        $params = [
            'openid.assoc_handle' => $get['openid_assoc_handle'],
            'openid.signed' => $get['openid_signed'],
            'openid.sig' => $get['openid_sig'],
            'openid.ns' => 'http://specs.openid.net/auth/2.0',
        ];
        foreach (explode(',', $get['openid_signed']) as $item) {
            $val = $get['openid_' . str_replace('.', '_', $item)];
            $params['openid.' . $item] = stripslashes($val);
        }
        $params['openid.mode'] = 'check_authentication';
        $data = http_build_query($params);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Accept-language: en\r\n" . "Content-type: application/x-www-form-urlencoded\r\n" . 'Content-Length: ' . strlen($data) . "\r\n",
                'content' => $data,
                'timeout' => $timeout,
            ],
        ]);
        preg_match($this->options['preg_check'], urldecode($get['openid_claimed_id']), $matches);
        $openID = (is_array($matches) && isset($matches[1])) ? $matches[1] : null;

        return preg_match("#is_valid\s*:\s*true#i", file_get_contents($this->options['openid_url'] . '/' . $this->options['api_key'], false, $context)) === 1 ? $openID : null;
    }

    /**
     * @param null|string $openID
     *
     * @return mixed
     * @throws JsonException
     */
    private function getData(?string $openID = null): mixed
    {
        if (null !== $openID) {
            return Json::decode(file_get_contents(str_replace('#openid#', $openID, $this->profileUrl)), 1);
        }

        return null;
    }

    /**
     * @return RedirectResponse
     */
    public function redirect(): RedirectResponse
    {
        $redirectUri = $this->router->generate($this->options['redirect_route'], $this->options['provider_options']['redirect_route_params'] ?? [], UrlGeneratorInterface::ABSOLUTE_URL);

        return new RedirectResponse($this->urlPath($redirectUri));
    }

    /**
     * @param string|null $return
     * @param string|null $altRealm
     *
     * @return string
     */
    public function urlPath(?string $return = null, ?string $altRealm = null): string
    {
        $realm = $altRealm ?: Http::getSchema($this->request) . $this->request->server->get('HTTP_HOST');
        if (null !== $return) {
            if (!$this->validateUrl($return)) {
                throw new RuntimeException('error_oauth_invalid_return_url');
            }
        } else {
            $return = $realm . $this->request->server->get('SCRIPT_NAME');
        }

        return $this->options['openid_url'] . '/' . $this->options['api_key'] . '/?' . http_build_query($this->getParams($return, $realm));
    }

    /**
     * @param string $url
     *
     * @return bool
     */
    #[Pure] private function validateUrl(string $url): bool
    {
        return Uri::isUrl($url);
    }

    /**
     * @param string $return
     * @param string|null $realm
     *
     * @return array
     */
    #[ArrayShape(['openid.ns' => "string", 'openid.mode' => "string", 'openid.return_to' => "string|string[]", 'openid.realm' => "null|string", 'openid.identity' => "string", 'openid.claimed_id' => "string", 'openid.sreg.required' => "array|mixed", 'openid.ns.sreg' => "string"])]
    private function getParams(string $return, ?string $realm): array
    {
        if (isset($this->options['provider_options']['replace'])) {
            $opt = $this->options['provider_options']['replace'];
            $return = str_replace(array_keys($opt), $opt, $return);
        }

        $params = [
            'openid.ns' => 'http://specs.openid.net/auth/2.0',
            'openid.mode' => 'checkid_setup',
            'openid.return_to' => $return,
            'openid.realm' => $realm,
            'openid.identity' => 'http://specs.openid.net/auth/2.0/identifier_select',
            'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
        ];
        if (($this->options['provider_options']['ns_mode'] ?? '') === 'sreg') {
            $params['openid.ns.sreg'] = 'http://openid.net/extensions/sreg/1.1';
            $params['openid.sreg.required'] = $this->options['provider_options']['sreg_fields'] ?? [];
        }

        return $params;
    }
}
