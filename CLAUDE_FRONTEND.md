# 💻 ERP Créditos - FRONTEND PASO A PASO DESDE CERO

**Proyecto**: umax-frontend
**Ubicación**: `C:\Users\USER\Dev\abrahamalanya\umax-frontend`
**Stack**: React + TypeScript + Vite + npm
**Backend**: API en `http://umax.test` (proyecto `umax`, ver `CLAUDE_PASO_A_PASO.md`)
**Objetivo**: Crear un proyecto React limpio, sin librería de UI todavía, capaz de hablar con la API del ERP (login + rutas protegidas). La librería de UI (Tailwind, shadcn, MUI, Mantine, etc.) se decide y añade DESPUÉS, en una fase aparte.

---

## 🎯 Decisiones CONFIRMADAS

```
✅ Framework: React (Vite)
✅ Lenguaje: TypeScript
✅ Package manager: npm
✅ Ubicación: Dev/abrahamalanya (NO dentro de Herd — Vite corre su propio dev server)
✅ Auth: Bearer token (Sanctum personal access tokens), NO cookies SPA
✅ UI library: PENDIENTE — no instalar nada de UI en esta fase
```

**Por qué NO va en Herd**: Herd sirve proyectos PHP vía su propio servidor con dominios `.test`. Un proyecto Vite/React corre su propio servidor de desarrollo (`npm run dev`, con HMR) en un puerto propio (por defecto `http://localhost:5173`). Meterlo en la carpeta de Herd no aporta nada.

**Por qué Bearer token y no cookies SPA de Sanctum**: el `AuthController` del backend ya genera tokens con `createToken()->plainTextToken` (ver `app/Http/Controllers/Api/AuthController.php`). Es el modo más simple para un frontend en otro origen/puerto — solo se manda `Authorization: Bearer <token>` en cada request. El modo cookie-based de Sanctum (SPA authentication) exige mismo dominio de nivel superior y configuración extra de CORS/cookies que no necesitamos aquí.

---

# 📍 PASO 1: CREAR PROYECTO VITE + REACT + TYPESCRIPT (10 minutos)

## ✅ Paso 1.1: Verificar Node y npm

```bash
node --version
npm --version
```

**Debe mostrar Node 18+ (idealmente 20+ LTS)**

✅ **Checklist**: Node y npm disponibles

---

## ✅ Paso 1.2: Crear el Proyecto

```bash
cd ~/Dev/abrahamalanya
npm create vite@latest umax-frontend -- --template react-ts
```

**Esperar** mientras se genera el scaffold.

✅ **Checklist**: Proyecto generado

---

## ✅ Paso 1.3: Entrar e Instalar Dependencias

```bash
cd umax-frontend
npm install
```

✅ **Checklist**: Dependencias instaladas

---

## ✅ Paso 1.4: Verificar que Corre

```bash
npm run dev
```

**Debe mostrar:**
```
VITE vX.X.X  ready in XXX ms
➜  Local:   http://localhost:5173/
```

**Abrir esa URL en el navegador** — debe verse la página por defecto de Vite + React.

✅ **Checklist**: Servidor de desarrollo funciona

---

# 📍 PASO 2: CONFIGURAR CONEXIÓN CON LA API (10 minutos)

## ✅ Paso 2.1: Crear `.env`

**En la raíz del proyecto, crear** `.env`:

```env
VITE_API_URL=http://umax.test/api
```

**Crear también** `.env.example` con el mismo contenido (para versionar el nombre de la variable sin valores sensibles).

✅ **Checklist**: `.env` creado

---

## ✅ Paso 2.2: Verificar CORS en el Backend

Por defecto, el middleware `HandleCors` de Laravel 12 permite cualquier origen (`allowed_origins => ['*']`) para las rutas `api/*`, sin necesidad de publicar `config/cors.php`. Como usamos Bearer token (no cookies), **no** necesitamos `supports_credentials`, `withCredentials` ni `SANCTUM_STATEFUL_DOMAINS`.

**Si al hacer requests desde el navegador aparece un error de CORS:**

```bash
# En el proyecto umax (backend)
php artisan config:publish cors
```

Y en `config/cors.php`, agregar el origen del frontend a `allowed_origins`:

```php
'allowed_origins' => ['http://localhost:5173'],
```

✅ **Checklist**: CORS verificado (o corregido si hizo falta)

---

# 📍 PASO 3: ESTRUCTURA MÍNIMA DE CARPETAS (5 minutos)

## ✅ Paso 3.1: Crear Carpetas

```bash
mkdir -p src/api
mkdir -p src/types
mkdir -p src/pages
mkdir -p src/hooks
```

✅ **Checklist**: Carpetas creadas (sin componentes de UI todavía)

---

# 📍 PASO 4: CLIENTE API BASE (SIN LIBRERÍA EXTERNA) (15 minutos)

## ✅ Paso 4.1: Tipos Base

**Crear:** `src/types/api.ts`

```ts
export interface ApiResponse<T> {
  success: boolean;
  message: string;
  data: T;
}

export interface User {
  id: number;
  nombre: string;
  apellido: string;
  email: string;
  estado: string;
  roles?: { id: number; name: string }[];
}

export interface LoginData {
  user: User;
  access_token: string;
  token_type: string;
}
```

✅ **Checklist**: Tipos creados

---

## ✅ Paso 4.2: Cliente HTTP (fetch nativo)

**Crear:** `src/api/client.ts`

```ts
const API_URL = import.meta.env.VITE_API_URL;

function getToken(): string | null {
  return localStorage.getItem('access_token');
}

export async function apiFetch<T>(
  path: string,
  options: RequestInit = {}
): Promise<T> {
  const token = getToken();

  const response = await fetch(`${API_URL}${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });

  const json = await response.json();

  if (!response.ok) {
    throw new Error(json.message ?? 'Error de red');
  }

  return json as T;
}
```

✅ **Checklist**: Cliente HTTP creado

---

## ✅ Paso 4.3: Funciones de Auth

**Crear:** `src/api/auth.ts`

```ts
import { apiFetch } from './client';
import type { ApiResponse, LoginData, User } from '../types/api';

export function login(email: string, password: string) {
  return apiFetch<ApiResponse<LoginData>>('/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  });
}

export function logout() {
  return apiFetch<ApiResponse<null>>('/auth/logout', { method: 'POST' });
}

export function me() {
  return apiFetch<ApiResponse<User>>('/auth/me');
}
```

✅ **Checklist**: Funciones de auth creadas

---

# 📍 PASO 5: PROBAR LA CONEXIÓN (10 minutos)

## ✅ Paso 5.1: Reemplazar `src/App.tsx` (prueba temporal, sin UI final)

```tsx
import { useState } from 'react';
import { login, me } from './api/auth';
import type { User } from './types/api';

function App() {
  const [email, setEmail] = useState('abrahamalanya@laravel.com');
  const [password, setPassword] = useState('abrahamalanya');
  const [user, setUser] = useState<User | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function handleLogin() {
    setError(null);
    try {
      const res = await login(email, password);
      localStorage.setItem('access_token', res.data.access_token);
      setUser(res.data.user);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Error desconocido');
    }
  }

  async function handleMe() {
    setError(null);
    try {
      const res = await me();
      setUser(res.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Error desconocido');
    }
  }

  return (
    <div style={{ padding: 24, fontFamily: 'sans-serif' }}>
      <h1>umax — Prueba de conexión API</h1>
      <input value={email} onChange={(e) => setEmail(e.target.value)} />
      <input
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        type="password"
      />
      <button onClick={handleLogin}>Login</button>
      <button onClick={handleMe}>Ver /auth/me</button>
      {error && <p style={{ color: 'red' }}>{error}</p>}
      {user && <pre>{JSON.stringify(user, null, 2)}</pre>}
    </div>
  );
}

export default App;
```

✅ **Checklist**: Componente de prueba listo

---

## ✅ Paso 5.2: Probar en el Navegador

```bash
npm run dev
```

1. Abrir `http://localhost:5173`
2. Click en **Login** con `abrahamalanya@laravel.com` / `abrahamalanya`
3. Debe aparecer el JSON del usuario (con `roles: [{ name: "sistemas" }]`)
4. Click en **Ver /auth/me** — debe traer el mismo usuario usando el token guardado

✅ **Checklist**: Login funciona end-to-end desde el frontend

---

# ✅ FASE FRONTEND (BASE) COMPLETADA

```
✅ Proyecto Vite + React + TypeScript creado
✅ npm como package manager
✅ .env con VITE_API_URL
✅ CORS verificado contra el backend
✅ Cliente API con fetch nativo (sin librerías extra)
✅ Auth por Bearer token (login, logout, me)
✅ Conexión probada end-to-end con el backend

ESTADO: LISTO PARA ELEGIR LIBRERÍA DE UI
```

---

# 🚀 PRÓXIMO PASO

Cuando confirmes qué librería de UI usar (Tailwind, shadcn/ui, MUI, Mantine, Chakra, etc.), se instala, se reemplaza el `App.tsx` de prueba por una estructura real de páginas/rutas (React Router), y se conecta con los endpoints de la Fase 2 del backend (Clientes/CRM).

---

**Stack**: React + TypeScript + Vite + npm
**Backend relacionado**: `CLAUDE_PASO_A_PASO.md` (proyecto `umax`)
