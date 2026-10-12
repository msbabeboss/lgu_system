<?php
// Helper to draw actor (stick figure)
function actor($x,$y,$label,$color='#1a4f7a'){
  $l2=explode("\n",$label);
  $out="<circle cx='$x' cy='$y' r='18' fill='none' stroke='$color' stroke-width='2.5'/>";
  $out.="<line x1='$x' y1='".($y+18)."' x2='$x' y2='".($y+58)."' stroke='$color' stroke-width='2.5'/>";
  $out.="<line x1='$x' y1='".($y+32)."' x2='".($x-26)."' y2='".($y+50)."' stroke='$color' stroke-width='2.5'/>";
  $out.="<line x1='$x' y1='".($y+32)."' x2='".($x+26)."' y2='".($y+50)."' stroke='$color' stroke-width='2.5'/>";
  $out.="<line x1='$x' y1='".($y+58)."' x2='".($x-20)."' y2='".($y+84)."' stroke='$color' stroke-width='2.5'/>";
  $out.="<line x1='$x' y1='".($y+58)."' x2='".($x+20)."' y2='".($y+84)."' stroke='$color' stroke-width='2.5'/>";
  $ty=$y+100;
  foreach($l2 as $i=>$t){
    $out.="<text x='$x' y='".($ty+$i*14)."' text-anchor='middle' fill='$color' font-size='11' font-weight='700'>$t</text>";
  }
  return $out;
}
// Helper to draw use case oval
function uc($cx,$cy,$w,$h,$label,$fill,$stroke,$sub='',$subcolor='#334155'){
  $rx=intval($w/2); $ry=intval($h/2);
  $out="<ellipse cx='$cx' cy='$cy' rx='$rx' ry='$ry' fill='$fill' stroke='$stroke' stroke-width='1.8'/>";
  $out.="<text x='$cx' y='".($cy-4)."' text-anchor='middle' fill='#1e293b' font-size='10.5' font-weight='700'>$label</text>";
  if($sub) $out.="<text x='$cx' y='".($cy+10)."' text-anchor='middle' fill='$subcolor' font-size='9' font-style='italic'>$sub</text>";
  return $out;
}
// Helper: line with label
function conn($x1,$y1,$x2,$y2,$label='',$dash=false,$color='#94a3b8'){
  $d=$dash?"stroke-dasharray='6 3'":"";
  $out="<line x1='$x1' y1='$y1' x2='$x2' y2='$y2' stroke='$color' stroke-width='1.4' $d marker-end='url(#arr)'/>";
  if($label){
    $mx=intval(($x1+$x2)/2); $my=intval(($y1+$y2)/2)-6;
    $out.="<text x='$mx' y='$my' text-anchor='middle' fill='$color' font-size='9' font-style='italic'>$label</text>";
  }
  return $out;
}
?>
