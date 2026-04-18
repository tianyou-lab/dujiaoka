@extends('morpho::layouts.default')

@section('content')
<div class="auth-container">
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-sm-10 col-md-8 col-lg-5 col-xl-4">
                <div class="auth-card">
                    <div class="auth-header">
                        <div class="auth-logo mb-4">
                            <h1 class="brand-name">{{ config('app.name', 'Dujiaoka') }}</h1>
                            <p class="brand-tagline">邮箱验证</p>
                        </div>
                    </div>
                    <div class="auth-body">
                        @if (session('message'))
                            <div class="alert alert-success">{{ session('message') }}</div>
                        @endif
                        <p class="text-center mb-4">请验证您的邮箱地址。我们已向您的邮箱发送了验证链接。</p>
                        <form method="POST" action="{{ route('verification.resend') }}">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">重新发送验证邮件</button>
                        </form>
                        <div class="mt-3 text-center">
                            <form method="POST" action="{{ route('logout') }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-link p-0">退出登录</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
