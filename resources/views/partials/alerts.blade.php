@if(session('success'))

    <div style="
        background:#ddffdd;
        border:1px solid green;
        padding:15px;
        margin-bottom:20px;
    ">

        {{ session('success') }}

    </div>

@endif

@if(session('error'))

    <div style="
        background:#ffdddd;
        border:1px solid red;
        padding:15px;
        margin-bottom:20px;
    ">

        {{ session('error') }}

    </div>

@endif

@if($errors->any())

    <div style="
        background:#ffdddd;
        border:1px solid red;
        padding:15px;
        margin-bottom:20px;
    ">

        <ul style="margin:0; padding-left:20px;">

            @foreach($errors->all() as $error)

                <li>{{ $error }}</li>

            @endforeach

        </ul>

    </div>

@endif