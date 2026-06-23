<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>ERP Dashboard</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
display:flex;
margin:0;
}

/* Sidebar */

.sidebar{
width:250px;
height:100vh;
background:#1f2937;
color:white;
padding:20px;
overflow-y:auto;
}

/* Main */

.main{
flex:1;
}

/* Navbar */

.navbar{
margin-left:0;
}

</style>

</head>
<body>

{{-- Sidebar --}}
@include('layouts.sidebar')


{{-- Main --}}
<div class="main">

<nav class="navbar navbar-dark bg-dark">
<div class="container-fluid">

<span class="navbar-brand">
Dashboard
</span>

<div class="text-white">
{{ auth()->user()->name }}
</div>

</div>
</nav>

<div class="container mt-4">

@if(session('success'))
<div class="alert alert-success">{{ session('success') }}</div>
@endif

@if(session('error'))
<div class="alert alert-danger">{{ session('error') }}</div>
@endif

@yield('content')

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>