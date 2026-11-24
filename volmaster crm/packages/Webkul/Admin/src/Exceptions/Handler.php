<?php

namespace Webkul\Admin\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Códigos HTTP que têm página própria (os demais viram 500).
     */
    private const PAGINAS_DE_ERRO = [401, 403, 404, 405, 413, 419, 429, 503];

    /**
     * Json error messages.
     *
     * @var array
     */
    protected $jsonErrorMessages = [];

    /**
     * Create handler instance.
     *
     * @return void
     */
    public function __construct(Container $container)
    {
        parent::__construct($container);

        $this->jsonErrorMessages = [
            '404' => trans('admin::app.errors.404.title'),
            '403' => trans('admin::app.errors.403.title'),
            '401' => trans('admin::app.errors.401.title'),
            '500' => trans('admin::app.errors.500.title'),
        ];
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  Request  $request
     * @return Response
     */
    public function render($request, Throwable $exception)
    {
        if (! config('app.debug')) {
            return $this->renderCustomResponse($exception);
        }

        return parent::render($request, $exception);
    }

    /**
     * Convert an authentication exception into a response.
     *
     * @param  Request  $request
     * @return Response
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->jsonErrorMessages[401]], 401);
        }

        return redirect()->guest(route('admin.session.create'));
    }

    /**
     * Render custom HTTP response.
     *
     * @return Response|null
     */
    private function renderCustomResponse(Throwable $exception)
    {
        if ($exception instanceof HttpException) {
            $statusCode = in_array($exception->getStatusCode(), self::PAGINAS_DE_ERRO)
                ? $exception->getStatusCode()
                : 500;

            return $this->response($statusCode);
        }

        if ($exception instanceof ValidationException) {
            return parent::render(request(), $exception);
        }

        // Sessão vencida e falta de permissão não são erro 500.
        if ($exception instanceof TokenMismatchException) {
            return $this->response(419);
        }

        if ($exception instanceof AuthorizationException) {
            return $this->response(403);
        }

        if ($exception instanceof ModelNotFoundException) {
            return $this->response(404);
        } elseif ($exception instanceof PDOException || $exception instanceof \ParseError) {
            return $this->response(500);
        } else {
            return $this->response(500);
        }
    }

    /**
     * Return custom response.
     *
     * @param  string  $path
     * @param  string  $errorCode
     * @return mixed
     */
    private function response($errorCode)
    {
        if (request()->expectsJson()) {
            return response()->json([
                'message' => isset($this->jsonErrorMessages[$errorCode])
                    ? $this->jsonErrorMessages[$errorCode]
                    : trans('admin::app.common.something-went-wrong'),
            ], $errorCode);
        }

        // Rota inexistente não passa pelo middleware de idioma: aplica o idioma configurado da oficina
        // (sem isso a 404 saía no idioma padrão do .env, em inglês se ele não tiver APP_LOCALE).
        try {
            app()->setLocale(core()->getConfigData('general.general.locale_settings.locale') ?: 'pt_BR');
        } catch (\Throwable) {
            // Sem banco ou sem configuração: segue com o idioma atual.
        }

        // Com o status verdadeiro (antes toda página de erro saía como HTTP 200).
        return response()->view('admin::errors.index', compact('errorCode'), (int) $errorCode);
    }
}
