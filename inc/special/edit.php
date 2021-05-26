<?php

if (!defined("SOFAWIKI")) die("invalid acces");

$wikis = array();
if ($action == "editmulti" || $action == "modifymulti") 
{
		$deeplsources = array();
		$targetlang='';
				
		foreach($swLanguages as $l)
		{
			//echotime($l);
			$w = new swWiki();
			//$w->parsers = $swParsers;
			
			// remove subpages
			$ns = explode("/",$wiki->name);
			$w->name = $ns[0]."/$l";
			
			$w->lookup();
			
			if (trim($w->content)!='')
			{
				$deeplsources[$l]= $w->content;
			}
			
			//echotime($w->name);
			//array_push($wikis,$w);
			$wikis[$l]=$w;
		}
		
		if (swGetArrayValue($_REQUEST,'submitdeepl',false))	
		{
			//echo "translating ";
			//echo $_REQUEST['submitdeepl'];
			//echo $_REQUEST['name'];
			
			$sourcelang = substr($_REQUEST['submitdeepl'],-2);
			$targetlang = substr($_REQUEST['name'],-2);
			
			//echo " $sourcelang $targetlang ";
			
			$w = $wikis[$sourcelang];
			$sourcetext = $w->content;
			
			//echo '<p>'.$sourcetext;
			
			$translated = swTranslate($sourcetext,$sourcelang,$targetlang);
			
			//echo '<p>'.$translated;
			
			//$w = $wikis[$targetlang];
			//$w->content = $translated;
			//$wikis[$targetlang] = $w;
			
			//print_r($wikis[$targetlang]);
			
			
			$action == "editmulti";
		}	
		
		$modifymode = "modifymulti";
}
else
{
	$wikis = array($wiki);
	$modifymode = "modify";
}

if (!$wiki->status)
	$wiki->name = str_replace("_"," ",$wiki->name);

if ($name && $wiki->status != "" || $modifymode == "modifymulti")
{
	$swParsedName = swSystemMessage("edit",$lang)." ".$wiki->name; 
	$namefieldtype = "hidden";
	$namespacelist  = "";
	$defaultnamespace = "";
	$helptext = swSystemMessage('modify-help',$lang);
}
else
{
	$s = $wiki->name;
	
	//clean up
	$s = str_replace("-"," ",$s);
	$s = $s = strtoupper(substr($s,0,1)).substr($s,1);
	$wiki->name=$s;
	
	
	$swParsedName = swSystemMessage("new",$lang)." ".$wiki->name ;
	$namefieldtype = "text";
	$helptext = swSystemMessage("new-help",$lang);
	
	
}
$swParsedContent = "<div id='editzone'>";

switch ($wiki->status)
{
	case "protected":
	
						if ($user->hasright("protect", $wiki->name))
						{
							$swParsedContent .= "
 <form method='post' action='".$wiki->link("unprotect")."'><p>
 <input type='Hidden' name='name' value='$name' />
 <input type='Submit' name='submitprotect' value='".swSystemMessage("unprotect",$lang)."' />
 </p></form>";
							$swParsedContent .= "\n<div class='preview'>".$wiki->parse()."\n</div>";
						}
						else
						{
							$swParsedContent .= "Protected";
							
							
						}
						
						break;
	
	case "deleted":
						if ($user->hasright("delete", $wiki->name))
						{
							$swEditMenus[] = "Deleted <a href='".$wiki->link("history")."'>".swSystemMessage("history",$lang)."</a>";
							$helptext = "";
						}
						else
						{
							$swParsedContent .= "Deleted";
							$helptext = "";
						}

						break;

	default:
						
						
						
						
						$swParsedContent .= "\n<table class='blanktable' style='width:100%'>\n<tr>";
						$cw = max(count($wikis),1);
						$cw = 100/$cw;
						$wikiwidth = ' style=" width:'.$cw.'%"';
						
						foreach ($wikis as $wikilang=>$wiki)
						{
							
							//print_r($wiki);
							$wiki->persistance = false;
							if ($action != 'new')
								$wiki->lookup(); // by revision again.
							$wiki->comment = '';
							
							
							$swParsedContent .= "\n<td valign=top $wikiwidth>";
							if (count($wikis)>1) $swParsedContent .= "<h4>$wiki->name</h4>";
							$lines = explode("\n",$wiki->content);
							$rows = max(count($lines) + 2, strlen($wiki->content)*count($wikis)/70, 3);
							$rows = min($rows,50);
							if (strlen($wiki->content)<2) $rows = 16;
							$cols = 100 / count($wikis);
							$codecols = $cols;
							if ($codecols==80) $codecols=100;
														
							$submitbutton = "";
							if ($user->hasright("modify", $wiki->name))
							{
								$submitbutton .= "<input type='submit' name='submit$modifymode' value='".swSystemMessage("save",$lang)."' />";
							}
							
							if ($modifymode == "modify")
							{								
							}
							else 
							{
								$rows = 20;
							}
							
							$deeplcontent = '';
							if ($action == 'editmulti' && (isset($swDeeplKey) && count($deeplsources)>0 && trim($wiki->content) == '' 
							&& in_array($wikilang,$swTranslateLanguages)))
							{
								
								$deeplcontent = '<form method="post" action="index.php?action=editmulti">';
								$deeplcontent .= '<input type="'.$namefieldtype.'" name="name" value="'.$wiki->name.'" style="width:60%" />';
								foreach($deeplsources as $k=>$v)
									if (in_array($k,$swTranslateLanguages))
									$deeplcontent .= '<input type="submit" name="submitdeepl" value="DeepL '.$k.'" />';
								$deeplcontent .= '</form>';
								
								// override wiki content
								if ($wikilang==$targetlang)
									$wiki->content = $translated;
								$swError = ''; // This page does not exist. 
							}
							
							
							$swParsedContent .= '
 <form method="post" action="index.php?action=modify">
 	<p id="editzonesubmit" style="width:100%; position:relative">
 		<input type="'.$namefieldtype.'" name="name" value="'.$wiki->name.'" style="width:60%" />
 		<input type="submit" name="submitcancel" value="'.swSystemMessage('cancel',$lang).'" />
	'.$submitbutton.'
	</p>
	<textarea class="editzonetextarea" name="content" rows='.$rows.' cols='.$cols.' style="width:100%" >'.$wiki->contentclean().'</textarea>
	<input type="hidden" name="revision" value="'.$wiki->revision.'">
	<p>'.swSystemMessage('comment',$lang).': 
	<input type="text" name="comment" value="'.$wiki->comment.'" style="width:90%" /> 
	</p>
</form>'. $deeplcontent;
						
						
							if ($modifymode == "modify")
							{
								if ($user->hasright("protect", $wiki->name) && $name && $wiki->status != "")
								{
									$swParsedContent .= "
 <p>
 <form method='post' action='".$wiki->link("protect")."'><p>
 <input type='submit' name='submitprotect' value='".swSystemMessage("protect",$lang)."' />
 </p></form>
";
								}
								if ($user->hasright("rename", $wiki->name) && $name 
								&& $wiki->status != "")
								{
									$swParsedContent .= "
 <form method='post' action='".$wiki->link("rename")."'><p>
 <input type='submit' name='submitdelete' value='".swSystemMessage("rename",$lang)."' />
 <input type='text' name='name2' value=\"$wiki->name\" style='width:60%;' />";
 
 if (!stristr($name,'/'))
 $swParsedContent .="<input type='checkbox' name='renamesubpages' value='1'/>".swSystemMessage("rename-subpages",$lang);
 $swParsedContent .=" </p></form>";
								}
								if ($user->hasright("delete", $wiki->name) && $name && $wiki->status != "")
								{
									$swParsedContent .= "
 <form method='post' action='".$wiki->link("delete")."'><p>
 <input type='submit' name='submitdelete' value='".swSystemMessage("delete",$lang)."' />
 </p></form>
";
								}
								
								
	
	
								
							}
							
							$swParsedContent .= "</td>";
						}
						$swParsedContent .= "</tr></table>";
}




$swParsedContent .= "\n</div><!-- editzone -->";
if ($helptext != "")
$swParsedContent .= "
<br/>
<div id='help'>
".swSystemMessage("modify-help",$lang). "
</div><!-- help -->";


$swFooter = "$wiki->name, ".swSystemMessage("revision",$lang).": $wiki->revision, $wiki->user, ".swSystemMessage("date",$lang).":$wiki->timestamp, ".swSystemMessage("status",$lang).":$wiki->status";
switch($wiki->integrity())
{
	case 0: $swFooter .= ' error checksum not ok'; break;
	case 1: $swFooter .= ' checksum ok'; break;
}
if(!$name) $swFooter = "";
if (!$wiki->revision) $swFooter="";
if (count($wikis)>1) $swFooter="";

?>