<section
    class="card unit-browser"
    id="organization-unit-tree"
    data-provinces-url="/api/provinces"
    data-communes-url="/api/communes"
    data-province-counts="{{ json_encode($unitProvinceCounts) }}"
>
    <div class="card-header bg-white">
        <div class="d-flex align-items-center justify-content-between gap-2">
            <div class="fw-semibold"><i class="fa-solid fa-sitemap me-2 text-secondary" aria-hidden="true"></i>Cây đơn vị</div>
        </div>
        <div class="small text-muted mt-1">Chọn địa bàn để lọc tài khoản.</div>
    </div>
    <div class="unit-browser-tree">
        @forelse ($unitProvinces as $province)
            <section
                class="unit-tree-node"
                data-unit-type="{{ $unitRootType }}"
                data-unit-code="{{ $province->code }}"
                data-unit-name="{{ $province->name }}"
                data-province-code="{{ $unitRootType === 'commune' ? $province->province_code : $province->code }}"
                @if (! $actor->hasRole(\App\Models\Role::SYSTEM_ADMIN)) data-scope-root @endif
                @if ($unitRootType === 'commune') data-commune-code="{{ $province->code }}" @endif
            >
                <div class="unit-tree-row">
                    <button class="unit-tree-toggle" type="button" data-tree-toggle aria-expanded="false" aria-label="Mở {{ $unitRootType === 'province' ? 'xã/phường thuộc' : 'thôn/bản thuộc' }} {{ $province->name }}">
                        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                        <span class="unit-tree-name">{{ $province->name }}</span>
                    </button>
                    <button class="unit-tree-select" type="button" data-unit-select aria-label="Lọc tài khoản theo {{ $province->name }}">
                        <span class="badge unit-tree-count">{{ number_format($unitProvinceCounts[$province->code] ?? $unitRootCount) }}</span>
                    </button>
                </div>
                <div class="unit-tree-children" data-unit-children hidden></div>
            </section>
        @empty
            <div class="p-3 small text-muted">Chưa có dữ liệu tỉnh/thành.</div>
        @endforelse
    </div>
    <div class="card-footer bg-white small text-muted">
        <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>
        Số bên cạnh là tài khoản trong đơn vị và các cấp dưới.
    </div>
</section>
