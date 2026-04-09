<?php
if (isset($_SESSION['message']) && !empty($_SESSION['message'])):
    $msg = $_SESSION['message'];
?>
<script>
document.addEventListener("DOMContentLoaded", () => {
    Swal.fire({
        title: <?= json_encode($msg['title']) ?>,
        text: <?= json_encode($msg['message']) ?>,
        icon: <?= json_encode($msg['type']) ?>,
        showConfirmButton: true,
        timer: 3000
    });
});
</script>
<?php
unset($_SESSION['message']);
endif;
?>
