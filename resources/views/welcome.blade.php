<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Laravel</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    <!-- Styles / Scripts -->
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <style>
            /* Tailwind CSS v4.0.7 */
            @layer base {
                /* Tailwind base styles */
            }
        </style>
    @endif
</head>

<body
    class="bg-[#FDFDFC] dark:bg-[#0a0a0a] text-[#1b1b18] flex p-6 lg:p-8 items-center lg:justify-center min-h-screen flex-col">
    <header class="w-full lg:max-w-4xl max-w-[335px] text-sm mb-6 not-has-[nav]:hidden">
        @if (Route::has('login'))
            <nav class="flex items-center justify-end gap-4">
                @auth
                    <a href="{{ url('/dashboard') }}"
                        class="inline-block px-5 py-1.5 dark:text-[#EDEDEC] border-[#19140035] hover:border-[#1915014a] border text-[#1b1b18] dark:border-[#3E3E3A] dark:hover:border-[#62605b] rounded-sm text-sm leading-normal">
                        Dashboard
                    </a>
                @else
                    <a href="{{ route('login') }}"
                        class="inline-block px-5 py-1.5 dark:text-[#EDEDEC] text-[#1b1b18] border border-transparent hover:border-[#19140035] dark:hover:border-[#3E3E3A] rounded-sm text-sm leading-normal">
                        Log in
                    </a>
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}"
                            class="inline-block px-5 py-1.5 dark:text-[#EDEDEC] border-[#19140035] hover:border-[#1915014a] border text-[#1b1b18] dark:border-[#3E3E3A] dark:hover:border-[#62605b] rounded-sm text-sm leading-normal">
                            Register
                        </a>
                    @endif
                @endauth
            </nav>
        @endif
    </header>
    <div
        class="flex items-center justify-center w-full transition-opacity opacity-100 duration-750 lg:grow starting:opacity-0">
        <main class="flex max-w-[335px] w-full flex-col-reverse lg:max-w-4xl lg:flex-row">
            <div
                class="text-[13px] leading-[20px] flex-1 p-6 pb-12 lg:p-20 bg-white dark:bg-[#161615] dark:text-[#EDEDEC] shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] rounded-bl-lg rounded-br-lg lg:rounded-tl-lg lg:rounded-br-none">
                <h1 class="mb-1 font-medium">Let's get started</h1>
                <p class="mb-2 text-[#706f6c] dark:text-[#A1A09A]">Laravel has an incredibly rich ecosystem. <br>We
                    suggest starting with the following.</p>
                <ul class="flex gap-3 text-sm leading-normal">
                    <li>
                        <!-- Upload Button -->
                        <button id="startUpload"
                            class="bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-4 rounded">
                            Upload Video
                        </button>
                        <input type="file" id="videoUpload" hidden accept="video/*">
                    </li>
                </ul>
            </div>
            <div
                class="bg-[#fff2f2] dark:bg-[#1D0002] relative lg:-ml-px -mb-px lg:mb-0 rounded-t-lg lg:rounded-t-none lg:rounded-r-lg aspect-[335/376] lg:aspect-auto w-full lg:w-[438px] shrink-0 overflow-hidden">
                <!-- Laravel Logo and Other SVGs -->
            </div>
        </main>
    </div>

    <!-- Upload Progress Popup -->
    <div id="uploadPopup"
        class="fixed bottom-5 right-5 w-80 bg-white border border-gray-300 rounded-lg shadow-lg z-50 hidden">
        <div class="flex justify-between items-center p-3 border-b border-gray-200">
            <h5 class="text-lg font-semibold">Uploading Files</h5>
            <button class="text-xl text-gray-600 hover:text-gray-800">&times;</button>
        </div>
        <div class="p-3">
            <!-- Dynamic content will be added here -->
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
        integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script>
        $(document).ready(function() {
            const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB chunks
            let activeUploads = {};

            // Set CSRF token for all AJAX requests globally
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                }
            });

            $('#startUpload').click(() => $('#videoUpload').click());

            $('#videoUpload').on('change', function(e) {
                const files = e.target.files;
                for (let file of files) {
                    startUpload(file);
                }
            });

            function startUpload(file) {
                const identifier = generateFileId(file);
                const totalChunks = Math.ceil(file.size / CHUNK_SIZE);

                // Add to upload list
                addUploadItem(identifier, file.name);

                // Process chunks
                uploadChunks(file, identifier, totalChunks);
            }

            function uploadChunks(file, identifier, totalChunks) {
                let uploadedChunks = 0;

                for (let chunkNumber = 1; chunkNumber <= totalChunks; chunkNumber++) {
                    const chunk = file.slice(
                        (chunkNumber - 1) * CHUNK_SIZE,
                        chunkNumber * CHUNK_SIZE
                    );

                    const formData = new FormData();
                    formData.append('file', chunk);
                    formData.append('chunkNumber', chunkNumber);
                    formData.append('totalChunks', totalChunks);
                    formData.append('identifier', identifier);
                    formData.append('originalName', file.name);

                    $.ajax({
                        url: '/upload-chunk',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        xhr: function() {
                            const xhr = new window.XMLHttpRequest();
                            xhr.upload.addEventListener('progress', function(e) {
                                if (e.lengthComputable) {
                                    const percent = (uploadedChunks * CHUNK_SIZE + e.loaded) /
                                        file.size * 100;
                                    updateProgress(identifier, percent);
                                }
                            }, false);
                            return xhr;
                        },
                        success: function() {
                            uploadedChunks++;
                            if (uploadedChunks === totalChunks) {
                                completeUpload(identifier);
                            }
                        },
                        error: function() {
                            handleUploadError(identifier);
                        }
                    });
                }
            }

            function generateFileId(file) {
                return `${file.name}-${file.size}-${Date.now()}-${Math.random().toString(36).substr(2)}`;
            }

            function addUploadItem(id, filename) {
                const html = `
            <div class="upload-item" id="upload-${id}">
                <div class="filename">${filename}</div>
                <div class="progress-bar">
                    <div class="progress" style="width: 0%"></div>
                </div>
                <div class="status">0%</div>
            </div>
        `;
                $('#uploadPopup .p-3').prepend(html);
                $('#uploadPopup').removeClass('hidden');
            }

            function updateProgress(id, percent) {
                $(`#upload-${id} .progress`).css('width', `${percent}%`);
                $(`#upload-${id} .status`).text(`${Math.round(percent)}%`);
            }

            function completeUpload(id) {
                $(`#upload-${id}`).addClass('completed');
                setTimeout(() => $(`#upload-${id}`).remove(), 2000);
            }

            function handleUploadError(id) {
                $(`#upload-${id}`).addClass('error');
                $(`#upload-${id} .status`).text('Upload Failed');
            }
        });
    </script>
</body>

</html>
