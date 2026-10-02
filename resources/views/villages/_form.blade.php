@if ($errors->any())
<div class="alert alert-danger" role="alert">
    <p class="mb-1">Vui lòng kiểm tra lại thông tin:</p>
    <ul class="mb-0">
        @foreach ($errors->all() as $error)
        <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif

<form method="POST" action="{{ $action }}">
    @csrf
    @if ($method !== 'POST')
    @method($method)
    @endif

    <div class="mb-3">
        <label for="village-code" class="form-label">Mã thôn/xóm <span class="text-danger">*</span></label>
        <input id="village-code" name="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code', $village->code) }}" maxlength="20" @if ($method !=='POST' ) readonly @endif required>
        @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="mb-3">
        <label for="village-name" class="form-label">Tên thôn/xóm <span class="text-danger">*</span></label>
        <input id="village-name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $village->name) }}" maxlength="255" required>
        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="mb-4">
        @include('partials.location-picker', [
        'pickerId' => 'village-form-location',
        'provinces' => $provinces,
        'provinceCode' => $provinceCode ?? '',
        'communeCode' => $village->commune_code,
        'provinceRequired' => true,
        'communeRequired' => true,
        ])
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit">
            <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i> {{ $method === 'POST' ? 'Lưu thôn/xóm' : 'Lưu thay đổi' }}
        </button>
        <a class="btn btn-outline-secondary" href="{{ route('villages.index', [], false) }}">Hủy</a>
    </div>
</form>