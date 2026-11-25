<?php

namespace App\Http\Controllers;

use App\Services\Installer;
use App\Services\InstallerException;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

class InstallerController extends Controller
{
    public function __construct(private Installer $installer) {}

    public function show(Request $request): View
    {
        return view('installer', [
            'authorized' => $this->installer->authorized($request->session()->get('installer.authorization')),
            'local' => $this->installer->isLocal(),
            'errors' => [],
        ]);
    }

    public function connect(Request $request): Response|RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => [$this->installer->isLocal() ? 'nullable' : 'required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'app_url' => ['bail', 'required', 'string', 'max:2048', 'url:http,https', function (string $attribute, string $value, Closure $fail): void {
                $parts = parse_url($value);
                if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                    $fail('La URL del panel no debe contener credenciales, parámetros ni fragmentos.');
                }
            }],
            'driver' => ['sometimes', 'required', Rule::in(['sqlsrv', 'mysql'])],
            'host' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9.:-]+\z/D', Rule::when($request->input('driver') === 'mysql', Rule::in(['localhost', '127.0.0.1', '::1']))],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:128', 'regex:/\A[\pL\pN _.-]+\z/uD'],
            'username' => ['required', 'string', 'max:255', 'regex:/\A[^\r\n\0]+\z/D'],
            'db_password' => ['nullable', 'string', 'max:1024', 'regex:/\A[^\r\n\0]*\z/D'],
            'trust_certificate' => ['nullable', 'boolean', 'prohibited_if:driver,mysql'],
        ], [
            'app_url.required' => 'Ingrese la URL definitiva del panel.',
            'app_url.string' => 'La URL del panel debe ser un texto.',
            'app_url.max' => 'La URL del panel es demasiado larga.',
            'app_url.url' => 'Ingrese una URL válida con http:// o https://.',
        ]);
        if ($validator->fails()) {
            return response()->view('installer', ['authorized' => false, 'local' => $this->installer->isLocal(), 'errors' => $validator->errors()->all()], 422);
        }

        $input = $validator->validated();
        $connection = [
            'driver' => $input['driver'] ?? 'sqlsrv',
            'host' => $input['host'],
            'port' => (string) $input['port'],
            'database' => $input['database'],
            'username' => $input['username'],
            'password' => $input['db_password'] ?? '',
            'trust_certificate' => (bool) ($input['trust_certificate'] ?? false),
        ];

        try {
            $authorization = $this->installer->prepare($input['code'] ?? null, $connection, rtrim($input['app_url'], '/'));
        } catch (InstallerException $exception) {
            return response()->view('installer', ['authorized' => false, 'local' => $this->installer->isLocal(), 'errors' => [$exception->getMessage()]], 422);
        } catch (Throwable $exception) {
            Log::error('No se pudo preparar la instalación.', ['exception' => $exception::class]);

            return response()->view('installer', ['authorized' => false, 'local' => $this->installer->isLocal(), 'errors' => ['No se pudo preparar la instalación. Revise la configuración y los permisos del servidor.']], 500);
        }

        $request->session()->regenerate();
        $request->session()->put('installer.authorization', $authorization);

        return redirect()->route('installer.show');
    }

    public function finish(Request $request): View|Response
    {
        $authorization = $request->session()->get('installer.authorization');
        abort_unless(is_string($authorization) && $this->installer->authorized($authorization), 403);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9_.-]+\z/D'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);
        if ($validator->fails()) {
            return response()->view('installer', ['authorized' => true, 'errors' => $validator->errors()->all()], 422);
        }

        $admin = $validator->validated();
        $admin['username'] = strtolower($admin['username']);

        try {
            $this->installer->finish($authorization, $admin);
        } catch (InstallerException $exception) {
            return response()->view('installer', ['authorized' => true, 'errors' => [$exception->getMessage()]], 422);
        } catch (Throwable $exception) {
            Log::error('No se pudo finalizar la instalación.', ['exception' => $exception::class]);

            return response()->view('installer', ['authorized' => true, 'errors' => ['No se pudo finalizar la instalación. Revise el estado de la base antes de reintentar.']], 500);
        }

        $request->session()->invalidate();

        return view('installer-complete');
    }
}
