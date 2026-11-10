{{-- For public pages that signed-in people also open: the signed-in app
     shell (sidebar, top bar, menu highlight) when someone is signed in, the
     public layout exactly as before when not. A page gives @section('title')
     as "Name · Local Goods" and an optional @section('active') menu key. --}}
@auth
    <x-app-shell :title="\Illuminate\Support\Str::beforeLast(trim($__env->yieldContent('title', 'Local Goods')), ' · Local Goods')" :active="trim($__env->yieldContent('active')) ?: null">
        {{-- The shell's <main> is already the page container. --}}
        <div class="shell-page">
            @yield('content')
        </div>
    </x-app-shell>
@else
    @include('layouts.app')
@endauth
