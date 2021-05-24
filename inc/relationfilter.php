<?php

if (!defined('SOFAWIKI')) die('invalid acces');


function swRelationTemplate($n)
{
	$wiki = new swWiki;
	$wiki->name = 'Template:'.$n;
	$wiki->lookup();
	if (!$wiki->revision)
		throw new swRelationError('Template page "'.$n.'" does not exist.',87);
	return $wiki->content;
}

function swRelationInclude($n)
{
	$wiki = new swWiki;
	$wiki->name = 'Template:'.$n;
	$wiki->lookup();
	if (!$wiki->revision)
		throw new swRelationError('Template page does not exist.',87);
	return $wiki->content;
}

function swRelationImport($url)
{
	
	// TO DO FILTER NAMESPACE HERE 
	global $user;
	global $swSearchNamespaces;
	global $swTranscludeNamespaces;
		
	$ns = array();
	$searcheverywhere = FALSE;
	foreach($swSearchNamespaces as $sp)
	{
		if (stristr($sp,'*')) $searcheverywhere = TRUE;
		$sp = swNameURL($sp);
		if (!stristr($sp,':')) $sp .= ':';
		if ($sp != ':') $ns[$sp]= $sp;
	}
	foreach($swTranscludeNamespaces as $sp)
	{
		if (stristr($sp,'*')) $searcheverywhere = TRUE;
		$sp = swNameURL($sp);
		if (!stristr($sp,':')) $sp .= ':';
		if ($sp != ':') $ns[$sp]= $sp;
	}
	
	if (!$searcheverywhere && stristr($url,':'))
		{
			$dnf =explode(':',$url);
			$dns = array_shift($dnf);
			if (! in_array($dns, $ns) && !$user->hasright('view',$url)) return array();
	}


	
	
	$wiki = new swWiki;
	$wiki->name = $url;
	$wiki->lookup();
	if (!$wiki->revision)
		throw new swRelationError('Import page does not exist.',87);
		
	$list = swGetAllFields($wiki->content);
	
	// normalize array, to a table, but using only used fields and field
	$maxcount = 1;
	foreach($list as $v)
	{
		$maxcount = max($maxcount,count($v));
	}	
	$list2 = array();
	foreach($list as $key=>$v)
	{
		for($fi=0;$fi<count($v);$fi++)
		{
			$list2[$fi][$key] = $v[$fi];
		}
		for ($fi=count($v);$fi<$maxcount;$fi++)
		{
			$list2[$fi][$key] = $v[count($v)-1];
		}
	}
	
	//print_r($list2);
	
	$header = array_keys($list);
	$result = new swRelation($header,null,null);
	
	foreach ($list2 as $v) 
	{
		$d = $v;
		$tp = new swTuple($d);
		$result->tuples[$tp->hash()] = $tp;
	}
	
	return $result;

}



function swRelationVirtual($url)
{
	// TO DO FILTER NAMESPACE HERE 
	global $user;
	global $swSearchNamespaces;
	global $swTranscludeNamespaces;

	$ns = array();
	$searcheverywhere = FALSE;
	foreach($swSearchNamespaces as $sp)
	{
		if (stristr($sp,'*')) $searcheverywhere = TRUE;
		$sp = swNameURL($sp);
		if (!stristr($sp,':')) $sp .= ':';
		if ($sp != ':') $ns[$sp]= $sp;
	}
	foreach($swTranscludeNamespaces as $sp)
	{
		if (stristr($sp,'*')) $searcheverywhere = TRUE;
		$sp = swNameURL($sp);
		if (!stristr($sp,':')) $sp .= ':';
		if ($sp != ':') $ns[$sp]= $sp;
	}
	
	if (!$searcheverywhere && stristr($url,':'))
		{
			$dnf =explode(':',$url);
			$dns = array_shift($dnf);
			if (! in_array($dns, $ns) && !$user->hasright('view',$url)) return array();
	}
	
	
	$wiki = new swWiki;
	$wiki->name = $url;
	$wiki->lookup();
	if (!$wiki->revision)
		throw new swRelationError('Virtual page does not exist.',87);
	
	$wiki->parsers[] = new swCacheparser;
	$wiki->parsers[] = new swTidyParser;
	$wiki->parsers[] = new swTemplateParser;
	
	$wiki->parse();
	
	$list = swGetAllFields($wiki->parsedContent);
	
	// normalize array, to a table, but using only used fields and field
	$maxcount = 1;
	foreach($list as $v)
	{
		$maxcount = max($maxcount,count($v));
	}	
	$list2 = array();
	foreach($list as $key=>$v)
	{
		for($fi=0;$fi<count($v);$fi++)
		{
			$list2[$fi][$key] = $v[$fi];
		}
		for ($fi=count($v);$fi<$maxcount;$fi++)
		{
			$list2[$fi][$key] = $v[count($v)-1];
		}
	}
	
	//print_r($list2);
	
	$header = array_keys($list);
	$result = new swRelation($header,null,null);
	
	foreach ($list2 as $v) 
	{
		$d = $v;
		$tp = new swTuple($d);
		$result->tuples[$tp->hash()] = $tp;
	}
	
	return $result;

}


function swRelationFilter($filter, $globals = array(), $refresh = false)
{
	// Filter syntax: field hint? (, field hint?)*
	// Fields can be all normal fields and all internal fields
	// Some special internal fields: _namespace, _word, paragraph
	// Wildcard field * can be used, if at least one field has a hint (else it would return the entire website
	// Hints can be expressions
	// Without hint, the fields is always included in the result even if it does not exist
	// With a wildcard hint *, the field must be present
	// With a text hint, the url-version of the hint must be present in the url-version of the field
	// If the hint as spaces, all words must be present, but not necessarily in that order (AND)
	// If the hint has pipes, at least one of the part must be present (OR)
	// If a fields is present multiple times, there are multiple results. Fields with less occurences are padded
	
	
	global $swIndexError;
	global $swMaxSearchTime;
	global $swMaxOverallSearchTime;
	global $swStartTime;
	global $swOvertime;
	$overtime = false;
	
	$verbose = 0;
	if (isset($_REQUEST['verbose'])) $verbose = 1;
	
	global $swRoot;
	global $db;
	$lastfoundrevision = 0;
	$goodrevisions = array();
	$bitmap = new swBitmap;
	$checkedbitmap = new swBitmap;
	$fields = array();
	
	if ($swIndexError) return new swRelation('');
	
	// parse query
	// currently, inline comma is not supported on hint.
	$pairs = explode(',',$filter);
	$fields = array(); 
	$getAllFields = false;
	$newpairs = array();
	$namefilter	= nulL;
	$namespacefilter = nulL;
	foreach($pairs as $p)
	{
		
		$p = trim($p);
		$elems = explode(' ',$p);
		$f = array_shift($elems);
		$h = null;
		
		if (count($elems)>0)
		{
			$h = join(' ',$elems); 
			
			$xp = new swExpression();
			$xp->compile($h);
			$h = $xp->evaluate($globals);
			
			/*
			if (substr($h,0,1) != '"' && substr($h,-1,1) != '"')
			{
				$h = '"'.@$globals[$h].'"';
			}
			elseif (substr($h,0,1) != '"')
				throw new swExpressionError('filter missing start quote '.$f,88);
			elseif (substr($h,-1,1) != '"')
				throw new swExpressionError('filter missing end quote '.$f,88);
			$h = substr($h,1,-1);
			*/
			
			if ($h=="") $h = '*';
		}
		if ($f == '*')	
			$getAllFields = true;
		elseif ($h == '*')
		{
			$hors2 = $fields[$f] = "*";
		}
		else	
			// make each individual url but keep spaces as separator
		{
			$hors = explode('|',$h);
			$hors2 = array();
			foreach ($hors as $hor)
			{
				$hands = explode(' ',$hor);
				$hands2 = array();
				
				//print_r($hands2);
				
				foreach($hands as $hand)
				{
					$hands2[] = swNameURL($hand);
				}
				
				$hors2[] = $hands2;
			}
			$fields[$f] = $hors2;
		}	
		if ($h == null)
			$newpairs[] = $f;
		else
			$newpairs[] = $f.' "'.$h.'"';
		if ($f == '_name')
			$namefilter = $hors2;
		if ($f == '_namespace')
			$namespacefilter = $hors2;
		
	}
	//print_r($fields);
	$filter = join(', ',$newpairs); // needed values for cache.
	echotime('filter '.$filter);
	
	// if * there must be at least one hint, we cannot return the entire database
	if ($getAllFields)
	{
		$found = false;
		foreach($fields as $hint)
		{
			if ($hint)
				$found = true;
		}
		if (!$found)
			throw new swExpressionError('filter missing at least one hint on * '.$f,88);
	}
	

	$header = array_keys($fields);
	
	
	$result = new swRelation($header,null,null);

	//return $result;
	
	// find already searched revisions
	$mdfilter = $filter;
	$mdfilter = urlencode($mdfilter);
	$cachefilebase = $swRoot.'/site/queries/'.md5($mdfilter);
	$cachefile = $cachefilebase.'.txt';
	
	if ($refresh)
		{ echotime('refresh'); if (file_exists($cachefile)) unlink($cachefile);}
	
	$chunks = array();
	if (file_exists($cachefile)) 
	{
		if ($handle = fopen($cachefile, 'r'))
		{
			// one file for header			
			while ($arr = swReadField($handle))
			{
				if (@$arr['_primary'] == '_header')
				{
					$bitmap = unserialize($arr['bitmap']);
					$checkedbitmap = unserialize($arr['checkedbitmap']);
					if (isset($arr['chunks']))
					$chunks = unserialize($arr['chunks']);
					unset($arr);
				}
			}
			fclose($handle);
			//echomem('primary');
			echotime('<a href="index.php?name=special:indexes&index=queries&q='.md5($mdfilter).'" target="_blank">'.md5($mdfilter).'.txt</a> ');
			
			// 1+ file for revisions
			$goodrevisions = array();
			foreach($chunks as $chunk)
			{
				$chunkfile = $cachefilebase.'-'.$chunk.'.txt';
				$s = file_get_contents($chunkfile);
				$goodrevisionchunk = @unserialize($s);
				unset($s);
				if (is_array($goodrevisionchunk))
					$goodrevisions = array_merge($goodrevisions,$goodrevisionchunk);
				else
				{
					$goodrevisions = array(); //reset;
					$bitmap = new swBitmap;
					$checkedbitmap = new swBitmap;  
					unset($chunks); 
				}
			}
			echotime('cached '.count($goodrevisions));
			echomem('cached');
		}
	}
	
	$cachechanged = false;
	$db->init();
	
	
	
	if (!is_a($bitmap, 'swBitmap'))  $bitmap = new swBitmap;
	if (!is_a($checkedbitmap, 'swBitmap'))  $checkedbitmap = new swBitmap;

	$maxlastrevision = $db->lastrevision;
	$indexedbitmap = $db->indexedbitmap->duplicate();
	if ($indexedbitmap->length < $maxlastrevision) $db->RebuildIndexes($indexedbitmap->length); // fallback
	$bitmap->redim($maxlastrevision+1,false);
	$checkedbitmap->redim($maxlastrevision+1,false);
	$currentbitmap = $db->currentbitmap->duplicate();
	$deletedbitmap = $db->deletedbitmap->duplicate();
	$notchecked = $checkedbitmap->notop();
	$tocheck = $indexedbitmap->andop($notchecked);
	unset($notchecked);
	$tocheck = $currentbitmap->andop($tocheck);
	$tocheckcount = $tocheck->countbits();
	if ($tocheckcount>0) 
	{ 
		echotime('tocheck '.$tocheckcount); 
	}
	$checkedcount = 0;
	
	foreach($goodrevisions as $k=>$v)
	{
		$kr = substr($k,0,strpos($k,'-')); 
		if($indexedbitmap->getbit($kr) && !$currentbitmap->getbit($kr))
		{
			$bitmap->unsetbit($kr);
			unset($goodrevisions[$k]);
			$cachechanged = true; 
			$checkedcount++;
		}
		if($deletedbitmap->getbit($kr))
		{
			$bitmap->unsetbit($kr);
			unset($goodrevisions[$k]);
			$cachechanged = true; 
			$checkedcount++;
		}
		if (!$indexedbitmap->getbit($kr))
		{
			$db->UpdateIndexes($kr); // fallback
		}
	}

	
	
	$nowtime = microtime(true);	
	$dur = sprintf("%04d",($nowtime-$swStartTime)*1000);
	if ($dur>$swMaxOverallSearchTime) {
		
		echotime('overtime overall');	
		$swOvertime = true;
	}	
	$tocheckcount = $tocheck->countbits();	
	if (($tocheckcount > 0 || $cachechanged) && $dur<=$swMaxOverallSearchTime)
	{
		
		
		if (($namefilter || $namespacefilter) && $filter != '_name, _revision')
		{
			$namerelation = swRelationFilter('_name, _revision', $globals);
			$tuples = $namerelation->tuples;
			
			
			foreach($tuples as $t)
			{
				$f = $t->fields();
				$n = swNameURL($f['_name']);
				
				if ($namespacefilter and $namespacefilter != '*')
				{
					$orfound = false;
					
					foreach($namespacefilter as $hor)
					{
						$andfound = true;
						
						if (!is_array($hor)) print_r($namespacefilter);
						else
						foreach($hor as $hand)
						{
							if ($hand && strstr($n,':') && !strstr($n,$hand.':')) $andfound = false;
						}
						
						
						if ($andfound) $orfound = true;
					}
					
					if (!$orfound) 
					{
						$tocheck->unsetbit($f['_revision']);
						continue;
					}
				}
				
				if ($namefilter and $namefilter != '*')
				{
					$orfound = false;
					
					foreach($namefilter as $hor)
					{
						$andfound = true;
						
						foreach($hor as $hand)
						{
							if ($hand && !strstr($n,$hand)) $andfound = false;
						}

						
						if ($andfound) $orfound = true;
					}
					
					if (!$orfound) 
					{
						$tocheck->unsetbit($f['_revision']);
						continue;
					}
				}
				
			}	
		}
		$tocheckcount = $tocheck->countbits();
		if ($filter != '_name, _revision')
			echotime('namefilter '.$tocheckcount); 			

			
		$bigbloom = new swBitmap();
		$bigbloom->init($tocheck->length,true);
		foreach($fields as $field=>$hors)
		{
			
			
			if (substr($field,0,1) != '_') 
			{
				$gr = swGetBloomBitmapFromTerm('-'.$field.'-'); // field has always [[ and :: or ]]
				$gr->redim($tocheck->length, true);
				$bigbloom = $bigbloom->andop($gr);
			
				if (is_array($hors))
				{
					$bor = new swBitmap();
					$bor->init($tocheck->length,false);
					
					foreach($hors as $hor)
					{
						$band = new swBitmap();
						$band->init($tocheck->length,true);
						
						foreach($hor as $hand)
						{
							if ($hand != '')
							{
								$gr = swGetBloomBitmapFromTerm($hand);
								$gr->redim($tocheck->length, true);
								$band = $band->andop($gr);
							}
						}
	
						
						$bor = $bor->orop($band);
						
					}
					$bigbloom = $bigbloom->andop($bor);	
				}
			}
		}
		$tocheck = $tocheck->andop($bigbloom);	
		
		$tocheckcount = $tocheck->countbits();
		if ($filter != '_name, _revision')
			echotime('bloom '.$tocheckcount); 			
				
		$toc = $tocheck->countbits();
		
		
		
		$checkedcount += $tocheckcount - $toc;
		if ($toc > 0 ) echotime('loop '.$toc);
		
		$starttime = microtime(true);
		if ($swMaxSearchTime<500) $swMaxSearchTime = 500;
		if ($swMaxOverallSearchTime<2500) $swMaxOverallSearchTime = 2500;

		// print_r($fields);
		
		

			
		if ($toc>0) 
		
		{
				//	echo " do ".$toc;
			
			for ($k=$maxlastrevision;$k>=1;$k--)
			{
				
				if (!$tocheck->getbit($k)) continue; // we already have ecluded it from the list
				if ($checkedbitmap->getbit($k)) continue; // it has been checked, should not happen here any more
			 	if(!$indexedbitmap->getbit($k)) continue; // it has not been indexed, should not happen here any more
				if(!$currentbitmap->getbit($k)) { $bitmap->unsetbit($k); continue; } // should not happen here any more
				if($deletedbitmap->getbit($k)) { $bitmap->unsetbit($k); $checkedbitmap->setbit($k); $checkedcount++; continue; }
				// should not happen here any more
				$checkedcount++;
				
				$nowtime = microtime(true);	
				$dur = sprintf("%04d",($nowtime-$starttime)*1000);
				if ($dur>$swMaxSearchTime) 
				{
					echotime('overtime '.$checkedcount.' / '.$tocheckcount);
					$overtime = true;
					$swOvertime = true;
					break;
				}
				$dur = sprintf("%04d",($nowtime-$swStartTime)*1000);
				if ($dur>$swMaxOverallSearchTime) 
				{
					echotime('overtime overall '.$checkedcount.' / '.$tocheckcount);
					$overtime = true;
					$swOvertime=true;
					break;
				}
				$record = new swWiki;
				$record->revision = $k;
				$record->lookup();
				
				if ($record->error == '') $checkedbitmap->setbit($k); else continue;
				$urlname = swNameURL($record->name);				
				
				$content = $record->name.' '.$record->content;
				$row=array();
				
				$fieldlist = $record->internalfields;

				{					
					$fieldlist['_revision'][] = $record->revision;
					$fieldlist['_status'][] = $record->status;
					$fieldlist['_name'][] = $record->name;
					$fieldlist['_url'][] = swNameURL($record->name);
					$fieldlist['_user'][] = $record->user;
					$fieldlist['_timestamp'][] = $record->timestamp;
					$fieldlist['_content'][] = $record->content;
					$fieldlist['_short'][] = substr($record->content,0,160);
				
					
					$fieldlist['_paragraph'] = explode(PHP_EOL, $record->content);
					
					
					$s = preg_replace("/[0123456789:\/.]/","-", $record->content);
					
					  
					
					
					$fieldlist['_word'] = explode('-', swNameURL($s));
					
					$fieldlist['_word'] = array_values(array_filter($fieldlist['_word'], function ($var){return strlen($var)>=3;})); 
					
				
					//print_r($fieldlist['_word']);
					
					
					$ns = swNameURL($record->wikinamespace());
					if ($ns == '') $ns = 'main';
					$fieldlist['_namespace'][] = $ns;
					
					
					$keys =array_keys($fieldlist);
					foreach($keys as $key)
					{
						if (substr($key,0,1) != '_')
						{
							$fieldlist['_field'][] = $key;	
							foreach($fieldlist[$key] as $v)
								$fieldlist['_any'][] = $v; // to do avoid duplicates
						}
					}
					
					//if (!isset($fieldlist[$field])) { $bitmap->unsetbit($k); continue; } ???
					
					// normalize array, to a table, but using only used fields and field
					// $maxcount = count($fieldlist[$field]);
					$maxcount = 1;
					// print_r($fields);
					foreach($fields as $key=>$v)
					{
						if (isset($fieldlist[$key]))
						{
							$maxcount = max($maxcount,count($fieldlist[$key]));
						}
					}	
					$fieldlist2 = array();
					foreach($fieldlist as $key=>$v)
					{
						if (array_key_exists($key,$fields) or in_array($key,array('_revision','_url'))
						or ($getAllFields and substr($key,0,1) != '_') )
						{
						
							for($fi=0;$fi<count($v);$fi++)
							{
								$fieldlist2[$fi][$key] = $v[$fi];
							}
							for ($fi=count($v);$fi<$maxcount;$fi++)
							{	
								if (count($v) > 0)
									$fieldlist2[$fi][$key] = $v[count($v)-1];
							}
						}
					}
					
					// print_r($fieldlist2);
					
					// select
					$rows = array();
					for ($fi=0;$fi<$maxcount;$fi++)
					{
						$revision = $fieldlist2[$fi]['_revision'];
						$found = true;
						foreach($fields as $key=>$hint)
						{
							$fieldfound = false;
							if ($hint=='')
							{
								$fieldfound = true;
								//echo "not";
							}
							elseif (array_key_exists($key,$fieldlist2[$fi]))
							{
								if ($hint == "*") 
								{
									$fieldfound = true;
																	}
								else
								{									
									$flv =  swNameURL($fieldlist2[$fi][$key]);
									//echo $flv.' ';
									
									$orfound = false;
									
									
									foreach($hint as $hor)
									{
										$andfound = true;
										
										foreach($hor as $hand)
										{
											
											if ($hand != '' && !strstr($flv,$hand)) $andfound = false;
										}
										
										if ($andfound) $orfound = true;
									}
									
									if ($orfound) 
									{
										$fieldfound = true;
									}

									
								}
								
							}
							
							if (!$fieldfound) $found = false;
						}
						
						if ($found)
							$rows[$revision.'-'.$fi] = $fieldlist2[$fi];
						

					}
					
					
					
					$maxcount = count($rows); 
					
					
										
					// extend missing
					foreach($fields as $key=>$hint)
					{
						
						for ($fi=0;$fi<$maxcount;$fi++)
						{
							$revision = $fieldlist2[$fi]['_revision'];
							if (isset($rows[$revision.'-'.$fi]) && !array_key_exists($key,$rows[$revision.'-'.$fi]))
							{
								$rows[$revision.'-'.$fi][$key] = '';
							}
						}
					}
								
				}
				
				

				if (count($rows)>0)
				{
					foreach($rows as $primary=>$line)
					{
						$goodrevisionchunknew[$primary] = $goodrevisions[$primary] = serialize($line);
					}
					$bitmap->setbit($k);

					$touched = true;
					
				}
				
			}
			
			
			// save to cache
			$bitmap->hexit();
			$checkedbitmap->hexit();
						
			echotime('checked '.$checkedcount);
			
			
			// do not cache if _content is part ( to expensive ) 
			
			if (!array_key_exists('_content',$fields)) {
			
				swSemaphoreSignal();
				echotime("filter write");
				
				
					
				if (isset($touched))
				{
					if (!isset($chunks) || count($chunks)==0)
						$chunks = array('1');
					$chunk = array_pop($chunks); $chunks[] = $chunk;
					//echotime('grc '.count($goodrevisionchunk));
					if (isset($goodrevisionchunk) && count($goodrevisionchunk) > 10000)
					{
						$chunk++;
						$chunks[] = $chunk;
						$chunkfile = $cachefilebase.'-'.$chunk.'.txt';
						
						$goodrevisionchunk = $goodrevisionchunknew;
						
					}
					elseif(isset($goodrevisionchunk) && is_array($goodrevisionchunk) && isset($goodrevisionchunknew) && is_array($goodrevisionchunknew)) 
					{
						$goodrevisionchunk=$goodrevisionchunk + $goodrevisionchunknew;
						//echotime('grc2 '.count($goodrevisionchunk));
					}
					else
					{
						$goodrevisionchunk = @$goodrevisionchunknew;
					}
					$chunkfile = $cachefilebase.'-'.$chunk.'.txt';
					$handle2 = fopen($chunkfile, 'w');
					fwrite($handle2,serialize($goodrevisionchunk));
					fclose($handle2);
					unset($goodrevisionchunk);
					unset($goodrevisionchunknew);
				}
				
				
				$handle2 = fopen($cachefile, 'w');
				$header = array();
				$header['filter'] = $filter;
				$header['mode'] = 'relation';
				$header['overtime'] = $overtime ;
				$header['chunks'] = serialize($chunks);
				$header['bitmap'] = serialize($bitmap);
				$header['checkedbitmap'] = serialize($checkedbitmap);
				$header['bitmapcount'] = $bitmap->countbits();
				$header['checkedbitmapcount'] = $checkedbitmap->countbits();
				$row = array('_header'=>$header);
				swWriteRow($handle2, $row );
				fclose($handle2);
				swSemaphoreRelease();
			}			
			
			echotime('good '.count($goodrevisions));
			echomem("filter");	
			} // if toc>0
	
	}
	
	//print_r($goodrevisions);
	
	// TO DO FILTER NAMESPACE HERE 
	global $user;
	global $swSearchNamespaces;
	global $swTranscludeNamespaces;
		
	$ns = array();
	$searcheverywhere = FALSE;
	foreach($swSearchNamespaces as $sp)
	{
		if (stristr($sp,'*')) $searcheverywhere = TRUE;
		$sp = swNameURL($sp);
		if (!stristr($sp,':')) $sp .= ':';
		if ($sp != ':') $ns[$sp]= $sp;
	}
	foreach($swTranscludeNamespaces as $sp)
	{
		if (stristr($sp,'*')) $searcheverywhere = TRUE;
		$sp = swNameURL($sp);
		if (!stristr($sp,':')) $sp .= ':';
		if ($sp != ':') $ns[$sp]= $sp;
	}
	
	//print_r($goodrevisions);
	
	// $searcheverywhere = true;
	
	$d = array();	
	
	foreach ($goodrevisions as $v) 
	{	
		$d = unserialize($v);
		
		$dn = @$d['_url'];
		
		if (!$searcheverywhere && stristr($dn,':'))
		{
			$dnf =explode(':',$dn);
			$dns = array_shift($dnf);
			if (! in_array($dns, $ns) && $user && !$user->hasright('view',$dn)) continue;
		}
		
		if (!in_array('_revision',$result->header)) unset($d['_revision']);
		if (!in_array('_url',$result->header)) unset($d['_url']);
		
		//print_r($d);
		
		$tp = new swTuple($d);
		$result->tuples[$tp->hash()] = $tp;
		
		//print_r($tp);
	}
	
	
	foreach($d as $key=>$val)
	{
		if (!in_array($key, $result->header))
			$result->addColumn($key);
	}
	
	//print_r($result);
	
	return $result;
	
}


function swRelationSearch($term, $start=1, $limit=100, $template="")

{

	
global $lang;
$previous = ' <nowiki><a href=\'index.php?action=search&start='.($start-$limit).'&query='.$term.'\'>'.swSystemMessage('previous',$lang).'</a></nowiki>';
$next = ' <nowiki><a href=\'index.php?action=search&start='.($start+$limit).'&query='.$term.'\'>'.swSystemMessage('next',$lang).'</a></nowiki>';	
$results = swSystemMessage('results',$lang);
$results1 = swSystemMessage('result',$lang);

if ($template && $template != 1)
	$print = '
project _name, _paragraph
template "'.$template.'"';
else
	$print = '
update _name = "<br>[["._name."]]<br>". _paragraph 
project _name
label _name ""
print grid '.$limit;


global $swSearchNamespaces;

$namespace = 'main|'.join('|',array_filter($swSearchNamespaces)); // filter removes empty values
if (trim($namespace)=='main') $namespace = "main";
if (stristr($namespace,'*')) $namespace = '*';
$namespace = strtolower($namespace);

//echo "($namespace)";


// note when result is empty, script fails with union, because aggregation has other arity than normal column

$term = swSimpleSanitize($term);
$singlequote = "'";

$q = '

filter _namespace "'.$namespace.'", _name, _paragraph "'.$term.'"
filter _namespace "'.$namespace.'", _name "'.$term.'", _paragraph
union
select trim(_paragraph) !== "" and substr(_paragraph,0,1) !== "#" and substr(_paragraph,0,2) !== "{{" and substr(_paragraph,0,6) !== "<code>"  and substr(_paragraph,0,2) !== "{|"
extend _nameint = regexreplace(_name,"\/\w\w","")
// print grid 20
project _nameint, _paragraph count, _paragraph first
rename _nameint _name, _paragraph_first _paragraph
order _paragraph_count 9
project _name, _paragraph
// add counter
dup
project _name count
set nc = _name_count
set start = '.$start.'
set limit = '.$limit.'
set ende = min(start+limit-1,nc)

if nc = 1
	set ncs = nc . " '.$results1.'"
else
	set ncs = nc . " '.$results.'"
	
if nc > limit
	set ncs =  start. " - " . ende . " / ". ncs
end if 
	
set other = 0
if '.$start.' > 1 
set ncs = ncs . "'.$previous.'"
set other = 1
end if
if '.($start+$limit-1).' < nc 
set ncs = ncs . "'.$next.'"
set other = 1
end if


echo ncs

pop

// show results with interesting paragraph
limit '.$start.' '.$limit.'

filter _namespace "main", _name, _paragraph "'.$term.'"
extend row = rownumber
project inline _name, row min
select row = row_min
project _name, _paragraph 

join left

// remove wiki styles
update _paragraph = (resume(_paragraph,160,1)

// create a query to be split
set v = "'.$term.'" ." "
set c = length(v)
set i = 1
set l = 0

// set query in paragraph bold
while i < c
if substr(v,i,1) == " " or substr(v,i,1) == "|"
if i > l
set s = substr(v,l,i-l)
update _paragraph = regexreplacemod(_paragraph,s,"<b>".s."</b>","i")
end if
set l = i + 1
end if
set i = i + 1
end while

'.$print.'





echo " "';




$lh = new swRelationLineHandler;


$s = $lh->run($q);

$s = str_replace("_name\t_paragraph",'',$s); // hack because raw includes header

return $s;


}


?>