@extends('morpho::layouts.default')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-8 col-lg-6">
            <div class="auth-card">
                <div class="auth-header">
                    <div class="auth-logo mb-3">
                        <h2 class="brand-name">修改密码</h2>
                    </div>
                </div>
                <div class="auth-body">
                    @if ($errors->any())
                        <div class="alert alert-danger">
                            @foreach ($errors->all() as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                        </div>
                    @endif
                    @if (session('success'))
                        <div class="alert alert-success">{{ session('success') }}</div>
                    @endif
                    <form method="POST" action="{{ route('user.change-password') }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">当前密码</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">新密码</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">确认新密码</label>
                            <input type="password" name="password_confirmation" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">保存修改</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
