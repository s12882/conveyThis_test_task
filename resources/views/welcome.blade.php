<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Laravel') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
            <div class="container">
                <a class="navbar-brand" href="{{ url('/') }}">{{ config('app.name', 'Laravel') }}</a>
            </div>
        </nav>

        <div class="container py-5">
            <div class="card">
                <div class="card-body">
                    <h1 class="card-title h4">File uploader</h1>
                    <p class="card-text text-muted">
                        Bootstrap + jQuery frontend scaffolding is wired up. Upload and file-management pages land here in later milestones.
                    </p>
                    <button type="button" class="btn btn-primary" id="ping-button">
                        Test Bootstrap + jQuery
                    </button>
                    <div class="alert alert-success mt-3 d-none" id="ping-alert" role="alert">
                        jQuery click handled, Bootstrap alert shown.
                    </div>
                </div>
            </div>
        </div>

        <script>
            $(function () {
                $('#ping-button').on('click', function () {
                    $('#ping-alert').removeClass('d-none');
                });
            });
        </script>
    </body>
</html>
