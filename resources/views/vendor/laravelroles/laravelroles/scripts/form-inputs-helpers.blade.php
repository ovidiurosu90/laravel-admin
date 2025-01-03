<script type="text/javascript">
function numbersAndLettersOnly()
{
    var ek = event.keyCode;
    // console.log(ek);
    if (48 <= ek && ek <= 57) { // 0 - 9
        return true;
    }
    if(65 <= ek && ek <= 90) { // A - Z
        return true;
    }
    if(97 <= ek && ek <= 122) { // a - z
        return true;
    }
    if (ek == 46) { // period '.'
        return true;
    }
    return false;
}
</script>

