<?php
require_once __DIR__ . '/includes/admin_gate.php';
ob_start();
session_start();
require_once "includes/conn.php";

if($_SERVER['REQUEST_METHOD']==='POST'){
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true,'msg'=>'Test worked! action='.($_POST['action']??'none')]);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head><title>Settings Test</title></head>
<body style="font-family:monospace;padding:2rem;background:#111;color:#ccc">
<h3>Settings AJAX Test</h3>
<button onclick="testPost()" style="padding:.5rem 1rem;background:#2d6b25;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:1rem">
  Click to test POST
</button>
<div id="result" style="margin-top:1rem;padding:1rem;background:#1a1a1a;border-radius:6px;min-height:40px"></div>
<script>
function testPost(){
    document.getElementById('result').textContent = 'Sending...';
    var fd = new FormData();
    fd.append('action','test');
    fetch('settings_test.php', {method:'POST', body:fd})
    .then(function(r){ return r.text(); })
    .then(function(t){
        document.getElementById('result').innerHTML = '<span style="color:#9c9">Raw response:</span><br>'+t;
        try{
            var d = JSON.parse(t);
            document.getElementById('result').innerHTML += '<br><span style="color:#9c9">Parsed OK: '+d.msg+'</span>';
        } catch(e){
            document.getElementById('result').innerHTML += '<br><span style="color:#f66">JSON parse failed: '+e.message+'</span>';
        }
    })
    .catch(function(e){
        document.getElementById('result').textContent = 'Fetch error: '+e.message;
    });
}
</script>
</body>
</html>
