<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="{{ url('/') }}">{{ config('app.name', 'Laravel') }}</a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#navbarNav"
            aria-controls="navbarNav"
            aria-expanded="false"
            aria-label="Toggle navigation"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('upload.create') ? 'active' : '' }}" href="{{ route('upload.create') }}">Upload</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('files.index') ? 'active' : '' }}" href="{{ route('files.index') }}">Manage Files</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
